// bh-live's real-time chat Worker, deployed to the user's Cloudflare account by
// BHL_WorkersChat::deploy(). Anonymous and unmoderated: there is no bridge from
// WordPress identity, and anyone with the room URL can post under any name.
// One ChatRoom Durable Object per stream (idFromName(stream_id)).

const CHAT_HTML = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;font-family:-apple-system,sans-serif;background:#18181b;color:#fff;">
<div id="log" style="height:calc(100vh - 52px);overflow-y:auto;padding:10px;box-sizing:border-box;font-size:14px;"></div>
<form id="f" style="display:flex;gap:6px;padding:8px;position:fixed;bottom:0;left:0;right:0;background:#0d0d0f;box-sizing:border-box;">
<input id="n" style="width:110px;padding:8px;border:0;border-radius:4px;" placeholder="Name" maxlength="24">
<input id="m" style="flex:1;padding:8px;border:0;border-radius:4px;" placeholder="Say something…" maxlength="300">
<button style="padding:8px 16px;border:0;border-radius:4px;background:#2271b1;color:#fff;">Send</button>
</form>
<script>
var streamId = new URLSearchParams(location.search).get('stream') || 'default';
var ws = new WebSocket(location.origin.replace('https', 'wss').replace('http', 'ws') + '/ws?stream=' + encodeURIComponent(streamId));
var log = document.getElementById('log');
ws.onmessage = function (e) {
  var d = JSON.parse(e.data);
  var p = document.createElement('div');
  p.style.marginBottom = '4px';
  p.style.wordBreak = 'break-word';
  var strong = document.createElement('strong');
  strong.style.color = '#5eb3f0';
  strong.textContent = d.name + ': ';
  p.appendChild(strong);
  p.appendChild(document.createTextNode(d.message));
  log.appendChild(p);
  log.scrollTop = log.scrollHeight;
};
document.getElementById('f').addEventListener('submit', function (e) {
  e.preventDefault();
  var m = document.getElementById('m'), n = document.getElementById('n');
  if (!m.value.trim()) return;
  ws.send(JSON.stringify({ name: (n.value.trim() || 'Guest').slice(0, 24), message: m.value.trim().slice(0, 300) }));
  m.value = '';
});
</script>
</body></html>`;

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const streamId = url.searchParams.get('stream') || 'default';

    if (url.pathname === '/ws') {
      const id = env.CHAT_ROOM.idFromName(streamId);
      const stub = env.CHAT_ROOM.get(id);
      return stub.fetch(request);
    }

    return new Response(CHAT_HTML, { headers: { 'content-type': 'text/html; charset=utf-8' } });
  },
};

export class ChatRoom {
  constructor(state, env) {
    this.state = state;
    this.sessions = [];
  }

  async fetch(request) {
    if (request.headers.get('Upgrade') !== 'websocket') {
      return new Response('expected a websocket connection', { status: 426 });
    }

    const pair = new WebSocketPair();
    const [client, server] = Object.values(pair);
    server.accept();
    this.sessions.push(server);

    server.addEventListener('message', (msg) => {
      // Relay only; no server-side history, so late joiners see only new messages.
      for (const s of this.sessions) {
        try { s.send(msg.data); } catch (e) { /* a stale/closed session — drop it below on its own close event */ }
      }
    });

    server.addEventListener('close', () => {
      this.sessions = this.sessions.filter((s) => s !== server);
    });

    return new Response(null, { status: 101, webSocket: client });
  }
}
