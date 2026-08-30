# Runbook — updating the ecosystem on the live site

Written 2026-08-30 after a session spent rediscovering all of this the hard way. If you are about to push ecosystem plugin changes to `billyhume.net`, read this first.

## When to use this

- You merged plugin changes to `dev` / `master` and need them on the live site.
- The live site is showing stale behaviour (old JS, an old markup shape) after a change that *should* have landed.
- The Bundled Zip Freshness or GitHub Updates panel is flagging drift.

Not for: third-party plugins (WooCommerce, Rank Math, the Bluehost plugin, Etch) — Billy's dev manages those from wp-admin / WordPress.org. This workflow only ever touches the 14 folders in `tools/ecosystem-plugins.txt`.

## The two delivery paths (know which one you're on)

| | **A — GitHub Updates panel** | **B — `stable` → FTP** |
|---|---|---|
| What triggers it | A human clicking **Update now** in wp-admin | A push to the `stable` branch |
| Source | The plugin folder on the **`dev`** branch of `github.com/ajhrtmn/billyhume` | `deploy-ftp.yml` builds + FTP-syncs `deploy_staging/` |
| Currently live on billyhume.net | **Yes — this is the one in use** | Needs `FTP_SERVER` / `FTP_USERNAME` / `FTP_PASSWORD` / `FTP_REMOTE_PLUGINS_PATH` GitHub secrets. The workflow comments still say "PLACEHOLDERS — replace once real credentials exist," so treat path B as **not proven** until someone confirms those secrets are set. |
| Core plugin (`the-self-hosted-self`) | Updates the same way — it self-registers as a GitHub Updates source (`class-github-updates.php` → `load_sources()`). First-ever core update on a site that predates that self-registration is manual (drop the built zip in over FTP once). | Included in `PLUGIN_FOLDERS`. |

Branch flow feeding both: work on **`dev`** → merge to **`master`** (both run `checks.yml`) → promote to **`stable`** for path B. Path A reads `dev` directly, so a `dev` push is enough for it.

## Procedure — path A (the normal case)

### 1. Land the change on `dev` (and `master`)

```bash
git add -A && git commit -m "…"        # version header + CHANGELOG.md in the SAME commit (checks.yml advises on this)
git push origin dev
git checkout master && git merge dev --ff-only && git push origin master && git checkout dev
```

If you changed a **peer** plugin (anything but the core), also refresh its bundled copy in the same commit:

```bash
cd wp-content/plugins
rm -f the-self-hosted-self/bundled/<plugin>.zip
zip -rq the-self-hosted-self/bundled/<plugin>.zip <plugin> \
  -x '*/.git/*' '*/node_modules/*' '*/vendor/*' '*/.DS_Store'
# then bump the-self-hosted-self's own version + CHANGELOG for the zip refresh
```

The **Bundled Zip Freshness** panel (Debug Tools) tells you when you forgot — it compares each `bundled/*.zip`'s header against the installed version. `OUS_Installer::install_from_bundle()` extracts from these, so a stale bundle means "reinstalled, still old."

### 2. Wait for GitHub's raw CDN

`raw.githubusercontent.com` caches ~5 min. Confirm the new version is actually being served before touching wp-admin:

```bash
curl -s "https://raw.githubusercontent.com/ajhrtmn/billyhume/dev/wp-content/plugins/<plugin>/<plugin>.php" | grep -m1 "Version:"
```

### 3. Update in wp-admin

**The Self-Hosted Self → Debug Tools → GitHub Updates** (`admin.php?page=ous-debug#ous-section-ous-github-updates`).

1. **Check now.** This *queues* an async job (it never checks synchronously — a synchronous check timed the whole site out once, see `class-github-updates.php` history). On a host where WP-Cron is unreliable it also auto-submits the Job Queue's "Run due jobs now" for you.
2. Wait, then re-read the table. **The "On GitHub" column lags step 2's `curl` result by another 5–15 min** — the checker keeps its own transient. Click **Check now** again if it still shows the old number. Every session this session it took 1–2 extra Check-now cycles.
3. When the row says **Update available**, click **Update now** (a `confirm()` → an `admin-post.php` form POST → downloads the folder from `dev`, overwrites in place).

The "not installed here" clutter toggle at the top of that panel (and the ecosystem dashboard, and Bundled Zip Freshness) hides rows for plugins this site doesn't run. Option: `ous_updates_show_absent`.

### 4. Purge the page cache — do not skip this

Billy's host (Bluehost plugin) puts a page cache in front with an **8-hour TTL**. It will keep serving the *old rendered HTML*, which references the *old* `assets/js/*.js?ver=<OLD_VER>` — so the plugin files update but the site behaves as if they didn't. This is the single trap that cost the most time this session.

- **wp-admin admin bar → Caching → Purge All** (URL: `admin.php?...&nfd_purge_all=1`).
- Cloudflare, if in front: purge there too.
- `bh-courses ≥ 0.16.18` sends `DONOTCACHEPAGE` + `no-store` on `is_singular(['bh_lesson','bh_course'])`, so those pages *stop* being cached going forward — but a purge is still needed once to clear what's already stored.

### 5. Verify

```bash
# the deployed asset version now matches the plugin constant
curl -s "https://billyhume.net/lesson/<any-lesson>/" | grep -oE "courses\.js\?ver=[0-9.]+"
```

Then the smoke checks (also run in CI as the `smoke` job, `checks.yml`):

```bash
WP_BASE_URL="https://billyhume.net" npx playwright test --project=smoke
```

Loads `/`, `/account/`, `/courses/`, `/contests/`, `wp-login.php`; fails on 5xx, on a PHP fatal signature in the body, on a near-empty `<body>`, or on raw `[bhi_portal]` text (core inactive).

## Rollback

There is **no built-in "reinstall previous version"** button. To roll back:

1. `git revert <bad commit>` on `dev`, bump the version *down-then-up* isn't possible — bump it *forward* with a "revert X" changelog entry, push `dev` + `master`.
2. Re-run steps 2–5 above. **Update now** overwrites with whatever `dev` currently holds, so a forward-revert is the rollback.
3. If the bad version is causing a fatal and wp-admin itself is down: FTP the previous known-good folder in by hand (or the matching `the-self-hosted-self/bundled/<plugin>.zip` from a good commit), then fix forward.

Keep the change small enough that a forward-revert is always safe — this is why peer plugins shipped 6 point releases in one session rather than one big one.

## Escalation

| Symptom | Who / where |
|---|---|
| FTP secrets, host page cache, Cloudflare, DNS | Billy's dev — CodeGreer (Sarah), `hello@codegreer.com` |
| Bunny Stream (403 in the player, chapter sync, referrer allow-list, Autoplay toggle) | Billy's Bunny dashboard → Stream → the library. See `PRIVATE-VIDEO-SETUP.md`. |
| "Update now" 500s / the GitHub Updates panel is blank | `OUS_DebugLog` (Debug Tools → Console & Logs) first; then `class-github-updates.php` |
| Site fatal after an update | `wp-config.php`: flip `WP_DEBUG_DISPLAY` / `WP_DEBUG_LOG` on, read the parse-error line out of `wp-content/debug.log`, revert both when done (see `CLAUDE.md` — a comment inside a CSS-in-PHP string has caused exactly this) |

## Related

- `deploy-ftp.yml` — path B, and the build-verification step that fails a deploy on stale committed `.js`.
- `OPEN.md` "Blocked on AJ" — the path-B / GitHub-Updates flow has never been click-through verified end to end on a real host.
- `ETCH-COMPATIBILITY-NOTES.md` — why the live site's rendered markup can differ from what the plugin emitted.
- `CONVENTIONS.md` — version bump + CHANGELOG in one commit.
