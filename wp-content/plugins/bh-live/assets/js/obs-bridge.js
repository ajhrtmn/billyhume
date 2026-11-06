/**
 * Runs inside the automation-bridge page loaded as a hidden OBS Browser Source.
 * WHY: it lives in OBS's browser so it can reach the local obs-websocket, which
 * the WP server cannot. Independent jobs: drive OBS from ecosystem events, and
 * relay Twitch/YouTube chat into bh-live's chat.
 */
(function () {
    'use strict';

    var statusEl = document.getElementById('bhl-automation-status');
    function setStatus(text, ok) {
        if (statusEl) { statusEl.textContent = text; statusEl.style.color = ok ? '#0f0' : '#f80'; }
    }

    var ws = null;
    var identified = false;
    var reqCounter = 0;

    function b64(bytes) {
        var bin = '';
        var arr = new Uint8Array(bytes);
        for (var i = 0; i < arr.length; i++) bin += String.fromCharCode(arr[i]);
        return btoa(bin);
    }

    function sha256b64(str) {
        var enc = new TextEncoder().encode(str);
        return crypto.subtle.digest('SHA-256', enc).then(b64);
    }

    function connectOBS() {
        var url = 'ws://' + BHLAutomationData.obsHost + ':' + BHLAutomationData.obsPort;
        setStatus('Connecting to OBS at ' + url + '…', false);
        ws = new WebSocket(url);

        ws.onopen = function () { setStatus('Connected — waiting for Hello…', false); };
        ws.onclose = function () { identified = false; setStatus('Disconnected from OBS — retrying in 5s…', false); setTimeout(connectOBS, 5000); };
        ws.onerror = function () { setStatus('WebSocket error — check host/port/password in settings.', false); };

        ws.onmessage = function (evt) {
            var msg;
            try { msg = JSON.parse(evt.data); } catch (e) { return; }

            // Hello (op 0) — negotiate auth, then send Identify (op 1).
            if (msg.op === 0) {
                var d = msg.d || {};
                var identify = { op: 1, d: { rpcVersion: d.rpcVersion || 1, eventSubscriptions: 0 } };
                if (d.authentication && BHLAutomationData.obsPassword) {
                    var auth = d.authentication;
                    sha256b64(BHLAutomationData.obsPassword + auth.salt).then(function (secret) {
                        return sha256b64(secret + auth.challenge);
                    }).then(function (authString) {
                        identify.d.authentication = authString;
                        ws.send(JSON.stringify(identify));
                    });
                } else {
                    ws.send(JSON.stringify(identify));
                }
                return;
            }

            // Identified (op 2) — connection is ready for real requests.
            if (msg.op === 2) {
                identified = true;
                setStatus('Connected to OBS. Watching for ecosystem events…', true);
                startPolling();
                return;
            }
        };
    }

    function sendRequest(requestType, requestData) {
        if (!ws || !identified) return;
        reqCounter++;
        ws.send(JSON.stringify({
            op: 6,
            d: { requestType: requestType, requestId: 'bhl-' + reqCounter, requestData: requestData || {} },
        }));
    }

    // toggle_source targets are "Scene:Source"; SetSceneItemEnabled needs a numeric
    // sceneItemId, looked up fresh each time since scenes can change.
    function runAction(rule) {
        if (rule.action === 'switch_scene') {
            sendRequest('SetCurrentProgramScene', { sceneName: rule.target });
            return;
        }
        if (rule.action === 'toggle_source') {
            var parts = rule.target.split(':');
            if (parts.length !== 2) return;
            var sceneName = parts[0], sourceName = parts[1];
            reqCounter++;
            var lookupId = 'bhl-lookup-' + reqCounter;
            ws.send(JSON.stringify({
                op: 6,
                d: { requestType: 'GetSceneItemId', requestId: lookupId, requestData: { sceneName: sceneName, sourceName: sourceName } },
            }));
            var handler = function (evt) {
                var msg;
                try { msg = JSON.parse(evt.data); } catch (e) { return; }
                if (msg.op === 7 && msg.d && msg.d.requestId === lookupId) {
                    ws.removeEventListener('message', handler);
                    var itemId = msg.d.responseData && msg.d.responseData.sceneItemId;
                    if (itemId !== undefined) {
                        sendRequest('SetSceneItemEnabled', { sceneName: sceneName, sceneItemId: itemId, sceneItemEnabled: true });
                    }
                }
            };
            ws.addEventListener('message', handler);
        }
    }

    var afterTs = null;
    var pollTimer = null;
    var polling = false;

    function poll() {
        if (polling) return;
        polling = true;
        var url = BHLAutomationData.rest + 'automation/events?token=' + encodeURIComponent(BHLAutomationData.token)
            + (afterTs ? '&after_ts=' + encodeURIComponent(afterTs) : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (res) {
            if (!res || !res.success) return;
            (res.events || []).forEach(function (evt) {
                afterTs = evt.occurred_at;
                (evt.rules || []).forEach(runAction);
            });
        }).catch(function () {}).finally(function () { polling = false; });
    }

    function startPolling() {
        if (pollTimer) return;
        poll();
        pollTimer = setInterval(poll, 4000);
    }

    /* ---------------- chat relay ---------------- */

    // The bridge URL isn't tied to a stream post, so resolve the live stream from /status.
    var currentStreamId = 0;
    function resolveStreamId() {
        fetch(BHLAutomationData.rest + 'status').then(function (r) { return r.json(); }).then(function (res) {
            currentStreamId = (res && res.online && res.stream_id) ? res.stream_id : 0;
        }).catch(function () {});
    }

    function relayMessage(source, displayName, message) {
        if (!currentStreamId || !message) return; // nothing live to relay into yet
        fetch(BHLAutomationData.rest + 'chat/' + currentStreamId + '/relay', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: BHLAutomationData.token, source: source, display_name: displayName, message: message }),
        }).catch(function () {});
    }

    // Anonymous read-only Twitch IRC: 'justinfanNNNNN' with no PASS needs no app or OAuth.
    var twitchWs = null;
    function connectTwitch() {
        if (!BHLAutomationData.twitchChannel) return;
        var channel = BHLAutomationData.twitchChannel.toLowerCase().replace(/^#/, '').trim();
        if (!channel) return;
        twitchWs = new WebSocket('wss://irc-ws.chat.twitch.tv:443');
        twitchWs.onopen = function () {
            twitchWs.send('NICK justinfan' + Math.floor(Math.random() * 100000));
            twitchWs.send('JOIN #' + channel);
        };
        twitchWs.onclose = function () { setTimeout(connectTwitch, 5000); };
        twitchWs.onerror = function () {};
        twitchWs.onmessage = function (evt) {
            String(evt.data).split('\r\n').forEach(function (line) {
                if (!line) return;
                if (line.indexOf('PING') === 0) { twitchWs.send('PONG :tmi.twitch.tv'); return; }
                var m = line.match(/^:([^!]+)![^ ]+ PRIVMSG #[^ ]+ :(.*)$/);
                if (m) relayMessage('twitch', m[1], m[2]);
            });
        };
    }

    // Read-only YouTube Live Chat with a plain API key. Honors pollingIntervalMillis to save quota.
    var ytChatId = null;
    var ytNextToken = null;
    var ytPollMs = 5000;
    function ytPollOnce() {
        var url = 'https://www.googleapis.com/youtube/v3/liveChat/messages'
            + '?liveChatId=' + encodeURIComponent(ytChatId) + '&part=snippet,authorDetails'
            + '&key=' + encodeURIComponent(BHLAutomationData.youtubeApiKey)
            + (ytNextToken ? '&pageToken=' + encodeURIComponent(ytNextToken) : '');
        fetch(url).then(function (r) { return r.json(); }).then(function (res) {
            ytNextToken = res.nextPageToken || ytNextToken;
            ytPollMs = res.pollingIntervalMillis || ytPollMs;
            (res.items || []).forEach(function (item) {
                var name = item.authorDetails && item.authorDetails.displayName;
                var text = item.snippet && item.snippet.displayMessage;
                if (text) relayMessage('youtube', name || 'YouTube viewer', text);
            });
        }).catch(function () {}).finally(function () { setTimeout(pollYouTube, ytPollMs); });
    }
    function pollYouTube() {
        if (!BHLAutomationData.youtubeApiKey || !BHLAutomationData.youtubeVideoId) return;
        if (ytChatId) { ytPollOnce(); return; }
        var url = 'https://www.googleapis.com/youtube/v3/videos?part=liveStreamingDetails'
            + '&id=' + encodeURIComponent(BHLAutomationData.youtubeVideoId)
            + '&key=' + encodeURIComponent(BHLAutomationData.youtubeApiKey);
        fetch(url).then(function (r) { return r.json(); }).then(function (res) {
            var item = res.items && res.items[0];
            ytChatId = (item && item.liveStreamingDetails) ? item.liveStreamingDetails.activeLiveChatId : null;
            if (ytChatId) ytPollOnce();
            else setTimeout(pollYouTube, 15000); // no active chat yet — recheck less aggressively
        }).catch(function () { setTimeout(pollYouTube, 15000); });
    }

    connectOBS();
    connectTwitch();
    pollYouTube();
    resolveStreamId();
    setInterval(resolveStreamId, 15000);
})();
