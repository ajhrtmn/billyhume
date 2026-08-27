# Changelog — BH Tickets

Moved out of `bh-tickets.php` on 2026-08-25 — this plugin never got the same extraction the-self-hosted-self did on 2026-08-23, so its version history sat in source comments until now. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

1.0.3 — Cleared the Test Runner's one remaining red test, which had
stood long enough that STATE.md documented it as expected:
`for_user()` "includes the requester's own ticket." A real defect, not
a flaky assertion: `$wpdb` returns every column as a string, bigint
primary keys included, but the test compared strictly
(`in_array($id, array_column($rows,'id'), true)`) — `"12" !== 12`, so
the match never happened. The test was right; the model was loose —
`get()`/`for_user()`/`all()` each documented an `array<int, ...>` shape
their return values didn't actually honor. Fixed by normalizing `id`,
`user_id`, `assigned_to`, and `ticket_id` to `int` in one shared
private helper, making the documented shape genuinely true rather than
relaxing the test to accept strings (which would have left every other
strict-comparison caller broken and hidden the next instance). Checked
the whole ecosystem for the same latent bug (`array_column()` paired
with a strict `in_array`) — this was the only occurrence. Test Runner:
635/635 passing across 19 suites, fully green for the first time in
this project's recorded history.

1.0.2 — Real gap found in a functional-depth audit (this plugin's
admin/portal UI was fully present, but "does it actually do the
job" had never been checked end to end). BHT_Replies::maybe_notify()
already handled staff-reply and requester-reply notifications, and
its own doc comment openly admitted a brand-new, unassigned ticket
notifies nobody at all — bht/ticket_created fired for the event log
but nothing ever listened to it for a real notification. A support
plugin whose own description promises "staff triage from wp-admin"
needs staff to actually find out a ticket exists rather than relying
on someone happening to check the list. Added
BHT_Tickets::notify_staff_new_ticket(), called from create():
notifies every account holding bhcore_manage_tickets (matches
get_users(['capability' => ...]) used elsewhere in this ecosystem —
bh-courses' class-sessions.php/class-admin.php), skipping the
creator themself so a staff member opening their own ticket doesn't
self-notify. Verified live: submitted a real ticket through the
portal (/account/?panel=tickets), confirmed via direct DB query that
this install currently has exactly one staff account (billy, the
ticket's own creator) — correctly produced zero notification rows,
since the only eligible recipient was the creator being skipped by
design. The "notify a DIFFERENT staff member" path itself couldn't
be exercised without a second real staff account in this
environment; logic reviewed carefully instead (same get_users()/
OUS_Notifications::notify() shape already proven working in
maybe_notify() just above it in class-replies.php).

1.0.1 — Ecosystem quality Phase 2, brick 4/13: added native return
types and parameter types across all 9 includes files (70 findings,
both mechanical level-6 categories). Purely additive typing, no
behavior change. This plugin is now clean at PHPStan level 6 in
isolation.
NOT runtime-verified against a live install.

1.0.0 — First cut. Phase 3 of the OSS-integration master plan:
support/ticketing built in-house rather than integrating an existing
WordPress helpdesk plugin (Awesome Support was surveyed as UX
reference only — canned-response and assignment-model ideas, never
installed as a dependency), for the same reasoning that already ruled
out FluentCRM/Groundhogg for the marketing layer: a real third-party
ticketing plugin brings its own separate contact model, which would
compete with bh-crm's own wp_users-based identity rather than
building on it.
Two tables (bhtickets_tickets, bhtickets_replies — class-activator.php)
— a ticket is its own lifecycle, not a CPT, same "a table when the
shape doesn't fit post/meta" convention bh-crm's own bhcrm_notes/
bhcrm_projects already established. Every ticket belongs to a real
wp_user; links to its requester via BHCRM_Links (bh-crm's existing
generic typed relationship table) when bh-crm is active, and
contributes a summary to bh-crm's per-person Activity section via the
existing bh_crm_activity_summary filter — entirely optional, this
plugin works standalone with zero other feature plugins installed.
Staff triage: a plain top-level wp-admin page (BHT_Admin, capability
'bhcore_manage_tickets', new in the-self-hosted-self 3.10.3) — a real
top-level add_menu_page() call, not a risky cross-plugin submenu, per
CLAUDE.md's documented page-hook-resolution incident. Fan-facing: a
"My Tickets" panel on the account portal (BHT_Portal, bhi_portal_panels
filter) — open/view/reply to your own tickets, ownership re-checked
server-side on every POST.
First real NEW (not retrofitted) OUS_Integration registration
(the-self-hosted-self 3.10.0) — 'bh-tickets' contract, builtin_class =>
'BHT_Tickets', no enhancer registered yet (by design — this contract
starts with only a built-in implementation).
NOT runtime-verified against a live WordPress+MySQL install this
session; `php -l` clean on every file.
