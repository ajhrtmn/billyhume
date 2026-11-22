# Runbook — updating the live site

How changes on `dev` reach `billyhume.net`. Third-party plugins (WooCommerce, MailPoet, Etch, Rank Math, the Bluehost plugin) are updated from wp-admin as usual; this covers only this repo's plugins (`tools/ecosystem-plugins.txt`) and the theme.

## The normal path: GitHub Updates

1. **Commit on `dev`** with the version bump and CHANGELOG entry in the same commit, then push `dev` (only when Billy or AJ says to).
2. **Wait about 5 minutes** for GitHub's cache. To check what GitHub is serving:
   ```bash
   curl -s "https://raw.githubusercontent.com/ajhrtmn/billyhume/dev/wp-content/plugins/<plugin>/<plugin>.php" | grep -m1 "Version:"
   ```
   (With a private repo, check on the GitHub website instead; this URL needs a login.)
3. **Live wp-admin → Site Tools → Debug Tools → GitHub Updates → Check now.** It queues a background check; reload after a moment, and click Check now again if the "On GitHub" column still shows the old number.
4. **Update now** on each row that says "Update available". Update the core plugin first when it has an update.
5. **Purge the page cache**: admin bar → Caching → Purge All (and Cloudflare, if it's in front). The host caches pages for 8 hours, and a cached page still loads the old CSS/JS.
6. **Check**: open a lesson and a contest page (also at phone width) and confirm the asset version changed:
   ```bash
   curl -s "https://billyhume.net/lesson/<any-lesson>/" | grep -oE "courses\.js\?ver=[0-9.]+"
   ```

## Making the repository private

GitHub Updates reads the public repo anonymously. Before switching the repo to private:

1. Install core **3.22.1 or later** on the live site (it adds token support).
2. On GitHub: Settings → Developer settings → **Fine-grained tokens** → generate one with access to only `ajhrtmn/billyhume` and the permission **Contents: Read-only**. Give it a long expiry and note the date.
3. Live wp-admin → Debug Tools → GitHub Updates → **Private repository access** → paste the token → Save. (Or add `define('BH_GITHUB_TOKEN', '…');` to `wp-config.php`.)
4. Click **Check now** and confirm the versions still read correctly.
5. Only then: GitHub → repo Settings → General → Danger Zone → **Change visibility → Private**.
6. Click **Check now** once more to confirm updates still work.

When the token expires, updates stop with "check failed"; make a new one and paste it in.

## Rollback

There is no "previous version" button. `git revert` the bad commit on `dev`, bump the version forward with a "revert" changelog entry, push, and update again. If wp-admin itself is down, upload the previous plugin folder (from a good commit on GitHub) over FTP, then fix forward. Keep changes small so a forward revert is always safe.

## The other path: `stable` → FTP

`deploy-ftp.yml` syncs the plugin folders over FTP when the `stable` branch is pushed. It needs FTP secrets on GitHub and has never been confirmed working; GitHub Updates is the path in use.

## One-time cleanup after the November 2026 rescope

The repo dropped seven plugins (bh-streaming, bh-video, bh-live, bh-feedback, bh-registry, bh-social, self-hosted-self-admin-skin). On the live site, after installing core 3.22.0+: deactivate and delete any of them on the **Plugins** screen. Their database tables stay; nothing reads them.

## Who to ask

| Problem | Where |
|---|---|
| FTP, host page cache, Cloudflare, DNS | Billy's host developer (CodeGreer) |
| Bunny Stream (403s, chapters, allowed domains) | Bunny dashboard → Stream; see `PRIVATE-VIDEO-SETUP.md` |
| Update now fails or the panel is blank | Debug Tools → Console & Logs, then `class-github-updates.php` |
| White screen after an update | Turn on `WP_DEBUG_LOG` in `wp-config.php`, read `wp-content/debug.log`, turn it off again |
