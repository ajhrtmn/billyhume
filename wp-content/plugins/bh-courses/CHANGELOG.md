# Changelog — BH Courses

Newest first. Recent releases have their own line; older ones are summarized. The full detail of each change is in git.

0.17.2 - Wording is now a BH_Props provider: changes made with `wp bh set` (or the future admin screen) apply on top of words.php.

0.17.1 - Admin-screen wording (course, lesson and progress screens, sessions, Bunny tools) moved into `words.php`; output unchanged. Only non-admin words are sent to the browser. PHPStan fixes, shorter comments, CSS formatting and a condensed changelog.

0.17.0 - Every button label, heading and message students see moved into `words.php` (about 210 entries, grouped by screen), read through `BHC_Words` in PHP and `bhcWord()` in the browser. Wording unchanged. A test fails if a key is missing.

0.16.57 - Fix: course progress could read over 100% after lessons were shortened; boxes use `<div>` so the live theme's section padding doesn't apply.
0.16.56 - Clear message when a lesson page was left open too long; confirm before deleting a note; guest receipt guidance.
0.16.55 - Security: a course sold by price alone was open to any logged-in account. Downloads overhaul, video hardening, layout balance.
0.16.50 - Student walkthroughs round 2: notes sidebar, no dead ends, clearer access messages, accessible video overlays.
0.16.40–0.16.46 - Billy's "Low End Theory" requirements: sales pages, downloads per course and lesson, private notes with video bookmarks, resume where you left off, credits, private feedback, gift access and launch coupons through WooCommerce.
0.16.29–0.16.39 - Course card, lesson sidebar and lesson sizes are editable live in the Design Suite; inline editor CSS moved into files.
0.16.11–0.16.28 - Lesson video sizing, Bunny Stream player wiring (chapters, overlays, watch progress), survives the live theme stripping classes and the host page cache, list and progress styling.
0.16.0–0.16.10 - Lesson authoring cleanup: settings in the editor sidebar, an always-visible "+ Add a step" button, video source picker in plain view; front-end motion and interaction states.

## Earlier history, in brief

- 0.12–0.15: lesson-editor design pass; private video sources (signed R2, Bunny Stream) with in-editor upload; catalog rendered through the theme; watch-gate restyle.
- 0.9–0.11: YouTube-style video chapters on the seek bar, in-video question overlays, and watch-progress completion, working for uploads, YouTube, Vimeo and Bunny.
- 0.5–0.8: annotation completion tracking, course-authoring UX pass, block API fixes.
- 0.4.66–0.4.86: one-time course purchase (Billy's request), 1:1 session booking, Cloudflare Stream video steps, SEO fixes, TypeScript conversion, PHPStan level 6 clean, dead-code sweep, a fuller demo course seed.
- 0.4.25–0.4.38: course duplication as a template, schema.org data, Metrics widgets, callout / checklist / chord chart / audio A-B step types, course hero pages, cross-course mastery and achievements.
- 0.3–0.4.15: lessons built from blocks; quiz answer storage; certificates; real video progress tracking; shortcode-to-block conversion.
- Before 0.3: courses of ordered multistep lessons (text, image, quiz) with per-student progress and drip release.
