# Changelog — BH CRM

Newest first. Recent releases have their own line; older ones are summarized. The full detail of each change is in git.

2.4.28 - Wording is now a BH_Props provider: changes made with `wp bh set` (or the future admin screen) apply on top of words.php.

2.4.27 - New `words.php` (read through `BHCRM_Words`) holding the People and Project Tracker screens' wording; output unchanged. Shorter comments.

2.4.26 - Removed linking a project card to a person's streaming track (that plugin is gone). Card file uploads unchanged; old track-link rows still display.

2.4.25 - Removed the profile page's empty layout slot and its Design Suite preview.
2.4.24 - Dropped the Project Tracker from the Design Suite gallery (it's a management tool, not a style surface).
2.4.23 - The "View public profile" link only shows when public profiles are switched on.
2.4.22 - Fix found while seeding the project tracker with real content.
2.4.14–2.4.21 - Saved lists became email-campaign audiences; live "N of M people match" preview in the list builder; TypeScript conversion of the board, tag, sub-task and list-builder scripts; PHPStan level 6 clean.
2.4.0–2.4.6 - Phone-friendly kanban board; cards auto-complete in the last column; first Metrics dashboard widgets; drag-and-drop failures no longer discard a move silently.

## Earlier history, in brief

- 2.0–2.3: nested sub-task boards at every level, inline editing, progress bars, breadcrumbs.
- 1.8–1.9: people and projects became many-to-many; a unified per-person activity timeline (contest entries, purchases, emails); audit logging on deletes; permission fixes so phone numbers and other sensitive fields are admin-only.
- 1.4–1.7: timestamped notes with authors and reminders; tag chips with autocomplete; bulk actions on the people list; saved lists.
- 1.0–1.3: the People list on shared accounts, notes, tags, CSV export, and the project kanban board.
