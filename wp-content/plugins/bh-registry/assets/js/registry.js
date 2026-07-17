(function () {
  'use strict';

  var grid = document.getElementById('bhr-grid');
  var searchInput = document.getElementById('bhr-search');
  var protocolFilter = document.getElementById('bhr-protocol-filter');
  var submitOpen = document.getElementById('bhr-submit-open');
  var submitModal = document.getElementById('bhr-submit-modal');
  var submitClose = document.getElementById('bhr-submit-close');
  var submitBtn = document.getElementById('bhr-f-submit');
  var errorBox = document.getElementById('bhr-f-error');
  var stepForm = document.getElementById('bhr-submit-step-form');
  var stepVerify = document.getElementById('bhr-submit-step-verify');

  var debounceTimer = null;

  function protocolLabel(p) {
    return p === 'activitypub' ? 'ActivityPub' : 'RSS / Podcasting 2.0';
  }

  function renderArtists(artists) {
    if (!artists.length) {
      grid.innerHTML = '<p class="bhr-empty">No artists found.</p>';
      return;
    }
    grid.innerHTML = artists.map(function (a) {
      var badges = a.links.map(function (l) {
        return '<span class="bhr-badge bhr-badge-verified">&#10003; ' + protocolLabel(l.protocol) + '</span>';
      }).join('');
      return '<div class="bhr-card">' +
        '<div class="bhr-card-avatar" data-avatar="' + escapeHtml(a.avatar_url || '') + '"></div>' +
        '<div class="bhr-card-name">' + escapeHtml(a.display_name) + '</div>' +
        '<div class="bhr-card-links">' + badges + '</div>' +
        '<button type="button" class="bhr-report-link" data-artist-id="' + a.id + '">Report this artist entry</button>' +
        '</div>';
    }).join('');

    // Applied as a real style-property assignment, not string-concatenated
    // into the HTML/CSS itself — the browser treats this as one CSS value,
    // so it can't be used to break out into arbitrary CSS/markup the way
    // interpolating it directly into a style="" attribute string could.
    grid.querySelectorAll('.bhr-card-avatar').forEach(function (el) {
      var url = el.getAttribute('data-avatar');
      if (url) el.style.backgroundImage = 'url("' + url.replace(/"/g, '') + '")';
    });

    bindReportButtons();
  }

  // Reporting an entry (impersonation, an ownership dispute over the
  // linked feed/actor, harassment via the bio field, etc.) posts to
  // own-ur-shit's shared moderation queue (BHI_Reports) rather than
  // this plugin building its own — a human reviews it from the
  // Own Ur Shit → Reports admin page, same as any other plugin's
  // report button.
  function bindReportButtons() {
    if (!window.BHIData || !BHIData.rest) return; // core plugin's report queue isn't available — nothing to wire up
    grid.querySelectorAll('.bhr-report-link').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (!BHIData.loggedIn) { alert('Log in to report an entry.'); return; }
        var reason = prompt('What\'s wrong with this entry? (optional, but helps review)') || '';
        fetch(BHIData.rest + 'reports', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': BHIData.nonce },
          body: JSON.stringify({ target_type: 'registry_artist', target_id: parseInt(btn.dataset.artistId, 10), category: 'ownership', reason: reason }),
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (res) { alert(res.ok ? 'Reported — thanks, this will be reviewed.' : ((res.d && res.d.message) || 'Could not submit the report.')); })
          .catch(function () { alert('Could not reach the site right now.'); });
      });
    });
  }

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = s || '';
    return div.innerHTML;
  }

  function load() {
    grid.innerHTML = '<p class="bhr-empty">Loading…</p>';
    var params = new URLSearchParams();
    if (searchInput.value) params.set('search', searchInput.value);
    if (protocolFilter.value) params.set('protocol', protocolFilter.value);

    fetch(BHRData.rest + 'artists?' + params.toString())
      .then(function (r) { return r.json(); })
      .then(function (data) { renderArtists(data.artists || []); })
      .catch(function () { grid.innerHTML = '<p class="bhr-empty">Could not load the registry right now.</p>'; });
  }

  searchInput.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(load, 300);
  });
  protocolFilter.addEventListener('change', load);

  submitOpen.addEventListener('click', function () { submitModal.style.display = 'flex'; });
  submitClose.addEventListener('click', function () { submitModal.style.display = 'none'; });

  submitBtn.addEventListener('click', function () {
    errorBox.textContent = '';
    var body = {
      display_name: document.getElementById('bhr-f-name').value,
      bio: document.getElementById('bhr-f-bio').value,
      contact_email: document.getElementById('bhr-f-email').value,
      protocol: document.getElementById('bhr-f-protocol').value,
      url: document.getElementById('bhr-f-url').value,
    };
    fetch(BHRData.rest + 'submissions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
      .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
      .then(function (res) {
        if (!res.ok) {
          errorBox.textContent = (res.data && res.data.message) || 'Something went wrong.';
          return;
        }
        showVerifyStep(res.data);
      })
      .catch(function () { errorBox.textContent = 'Could not reach the registry right now.'; });
  });

  function showVerifyStep(data) {
    stepForm.style.display = 'none';
    stepVerify.style.display = 'block';
    var v = data.verification;
    stepVerify.innerHTML =
      '<p>Publish a plain-text file at:</p>' +
      '<p><code>' + escapeHtml(v.challenge_url) + '</code></p>' +
      '<p>containing exactly:</p>' +
      '<p><code>' + escapeHtml(v.expected_content) + '</code></p>' +
      '<button type="button" class="bhr-btn" id="bhr-verify-now">I\'ve published it — verify now</button>' +
      '<div id="bhr-verify-result" class="bhr-form-error"></div>';

    document.getElementById('bhr-verify-now').addEventListener('click', function () {
      var resultBox = document.getElementById('bhr-verify-result');
      resultBox.textContent = 'Checking…';
      fetch(BHRData.rest + 'submissions/' + data.link_id + '/verify', { method: 'POST' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          resultBox.textContent = res.message;
          if (res.verified) load();
        })
        .catch(function () { resultBox.textContent = 'Could not reach the registry right now.'; });
    });
  }

  load();
})();
