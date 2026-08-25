# Changelog — BH Feedback

Moved out of `bh-feedback.php` on 2026-08-25 — this plugin never got the same extraction the-self-hosted-self did on 2026-08-23, so its version history sat in source comments until now. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

0.1.5 — Ecosystem quality Phase 2, brick 3/13: added native return
types and parameter types across all 9 includes files (60 findings,
both mechanical level-6 categories). One small real fix: render_
shortcode()'s `ob_get_clean()` can return `false` (empty output
buffer stack); cast to `(string)` so the declared `: string` return
type is actually honest rather than just asserted. Everything else
purely additive typing, no behavior change. This plugin is now
clean at PHPStan level 6 in isolation.
NOT runtime-verified against a live install.

0.1.4 — This plugin's first PHPStan pass (newly added to
phpstan.neon's scanned paths this round). One finding: get_userdata()
needed an int, not the string post_author property it was given
directly (class-admin.php).
NOT runtime-verified against a live install — confirmed via a real
`vendor/bin/phpstan analyse` run. `php -l` clean.

0.1.0 — first build. Ecosystem depth-pass Tier 1d, per
ROADMAP-feedback-and-courses-v2.md's own scoping: v1 ships quick-take
+ detailed written feedback only; the timestamped-audio-annotation
tier is explicitly deferred (architecturally the same "pause a step,
show an overlay" mechanic already shipped for bh-courses' video
annotations this session — worth reusing that UI pattern directly
when that tier is actually built, not inventing a second one).

Reviewer model (AJ's own call, ecosystem depth-pass Tier 1d design
pass): a SELF-SERVE CLAIM QUEUE, not admin-assigned — any account
holding the `bhcore_review_submissions` capability can browse open
requests and claim one. That capability GATES THE WHOLE REVIEWER
ROLE (claiming, reviewing, and seeing the queue are one bundle), not
just queue visibility — matches the existing Roles-as-jobs UI
(the-self-hosted-self's OUS_RoleAssignment): granting the "Reviewer" job is
granting everything reviewing needs, nothing more to wire up
separately.
