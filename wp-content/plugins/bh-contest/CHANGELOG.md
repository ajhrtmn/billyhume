# Changelog — BH Contest

Newest first. Recent releases have their own line; older ones are summarized. The full detail of each change is in git.

3.16.1 - Admin-screen wording (contest settings, wizard, Live Console, results, moderation, judging, reveal controls) moved into `words.php`; output unchanged. Only non-admin words are sent to the browser. PHPStan fixes, shorter comments, merged duplicate CSS selectors and a condensed changelog.

3.16.0 - Every word voters and entrants see (player, log-in/sign-up box, submit and share boxes, results, Reveal Party, contest cards, archive, Contest Submissions tab) moved into `words.php` (`BHCON_Words` in PHP, `bhWord()` in the browser). `bh-common.js` is registered once and carries the words for every contest script. A test fails if a key is missing.

3.15.17 - The Contest Submissions tab only appears for people who have entered a contest.
3.15.16 - Moved the wizard's and settings boxes' inline styles and scripts into files.
3.15.15 - Security: share cards only render for published submissions.
3.15.10–3.15.14 - Vote-breakdown pie charts in admin; contest badge styles editable in the Design Suite; removed unused layout slots.
3.15.0–3.15.9 - The Contests page (`[bh_contest_library]`): every contest as a card, grouped Open now / Starting soon / Wrapped, with a stage track and deadlines; cards link to each contest's page.
3.12.0–3.14.0 - The player knows when voting has closed (banner, disabled buttons) and shows votes left per category; the archive became a real browsable library; full-width and mobile fixes.
3.10.0–3.11.3 - Live Console became the "run this contest now" hub; Reveal Party polish; bulk approve/reject for submissions.
3.8.0–3.9.0 - Contest Settings box rebuilt as a proper grid.

## Earlier history, in brief

- 3.7.x: guided "New Contest" wizard; reserve an entry before the audio is ready; contestants can edit details and replace audio; search, site-menu sync and SEO event data for contests; per-contest access settings; anime.js reveal; TypeScript conversion of every script; PHPStan level 6 clean.
- 3.5–3.6: blocks for the player, reveal and archive; shareable "I entered" / "Vote for me" images; Metrics widgets; activity events for the CRM timeline; audit logging on rejections.
- 3.3–3.4: judge/rubric scoring and hybrid judged + public contests; multi-round elimination; in-house IP + cookie anti-fraud signal.
- 3.0–3.2: Discord announcements, vote-write checks, Debug Tools seeding.
- Before 3.0: the core voting player, categories with per-category vote limits, the live Results Reveal and the contest archive.
