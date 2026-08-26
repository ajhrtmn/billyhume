# Changelog — BH MailPoet

Moved out of `bh-mailpoet.php` on 2026-08-23. See `CONVENTIONS.md` for why version history lives here and in git rather than in source.

Entries are newest-first, exactly as written in-file. Nothing reworded or dropped.

---

1.2.0 — Robustness pass across every MailPoet integration point,
prompted by a direct request to make sure MailPoet usage in this
ecosystem is deep and well-done, not just present. Read MailPoet's own
installed source (lib/API/MP/v1/Subscribers.php) rather than assuming
behavior from its public docs.

Found and fixed a real, ongoing consent bug: `subscribeToList()`
unconditionally moves a subscriber back to 'subscribed' unless their
status is ALREADY 'subscribed' — it never checks for 'unsubscribed'.
`sync_contact()` runs constantly (7 watched BH_Event types, WooCommerce
order completion, entitlement grants, every profile update, the daily
full resync) — every one of those was silently re-subscribing anyone
who had ever clicked unsubscribe, the moment they next logged in,
voted, bought something, or got a wallet credit. `sync_contact()` now
checks the subscriber's global AND per-list status first and skips the
resubscribe call entirely if either is 'unsubscribed'.

Added tag sync: bh-crm's free-text person tags (BHCRM_Tags) now mirror
onto the MailPoet subscriber via MailPoet's own Tags API
(tagSubscriber()/untagSubscriber()), namespaced `BH-CRM: ` so this
plugin only ever touches tags it applied itself — a tag added directly
in MailPoet's own UI is never clobbered. Driven by the bhcrm/tags_saved
BH_Event's own payload (no extra bh-crm read needed) for real-time
sync, plus a safety-net pass in the daily full resync covering
BHCRM_Tags::handle_bulk_tag(), which — confirmed by reading it — never
emits that event at all. This is a real, concrete "add more where
useful": lets a MailPoet campaign/automation segment by bh-crm tag
(e.g. "contest winner") without this plugin inventing its own segment
mechanism.

Verified both fixes end-to-end against the real local WP+MySQL+MailPoet
install (5.36.0): created a real subscriber, unsubscribed them via
MailPoet's own unsubscribe() call, re-ran sync_contact() and confirmed
they stayed unsubscribed; ran sync_tags() and confirmed tags were
added/removed correctly and only the namespaced ones were touched.

1.1.4 — Closes this plugin's own long-standing "NOT runtime-
verified" disclosure (class-sync.php's docblock, present since this
plugin was first written): the real MailPoet plugin was never
installed on this repo before, so every \MailPoet\API\MP\v1\API call
this plugin makes (getLists, addList, getSubscriber, addSubscriber,
subscribeToList, unsubscribeFromLists) was written against
MailPoet's documented API surface, never checked against the real
thing. A functional-depth audit installed the real, official
MailPoet plugin (5.36.0) and actually ran it: every method signature
grepped directly out of the real installed
wp-content/plugins/mailpoet/lib/API/MP/v1/API.php matches exactly,
and a real "Sync now" (Debug Tools) run produced 92 synced / 0
failed, confirmed via direct DB query that real subscriber rows
landed in MailPoet's own wp_mailpoet_subscribers table and that
get_or_create_list_id() correctly auto-created a real MailPoet
segment rather than silently failing. BHMP_InstantSync's real-time
hooks (registration, profile update, WooCommerce order completion,
entitlement grants, the ecosystem's own bh_event_emitted bus) were
also read and confirmed to match exactly what the Debug Tools UI's
own copy claims they do. No code change — this changelog entry
exists purely to record that the disclosure is now resolved, not
speculative.

1.1.3 — Added the missing 'bundled_zip' key to this plugin's own
'ous_registered_plugins' self-registration (found while auditing
bundled-zip coverage ecosystem-wide — the-self-hosted-self 3.10.18's own
changelog has the full story). Also newly hardcoded into
OUS_Registry::DEFAULTS itself, closing the same chicken-and-egg gap
bh-courses/bh-registry/bh-feedback already had fixed: this plugin's
self-registration only ever fires once it's already active, so an
inactive install was invisible to the ecosystem dashboard.

1.1.2 — Real bug fix found while investigating a Phase 4 dead-code-
triage flag: BHMP_Sync::remove_contact() was fully implemented and
its own docblock literally says "Account deletion / explicit 'stop
syncing this person' path," but nothing anywhere ever called it —
class-sync.php's init() was a deliberate no-op ("mirrors BHM_Wallet's
own init() reasoning"), and no ecosystem plugin has any account-
deletion hook at all right now. Net effect: deleting a WordPress
account never removed that person's MailPoet subscription — a real,
silent gap, mildly privacy-relevant. Fixed by hooking remove_contact()
to WordPress's own `delete_user` action (fires before the user row
is actually removed, so remove_contact()'s internal get_userdata()
call still resolves) via a thin void-returning closure (PHPStan
flags a bool-returning value from an action callback). remove_contact()
itself already no-ops harmlessly via its class_exists('\MailPoet\API\
API') guard when MailPoet isn't installed, so this hook is safe
unconditionally. NOT runtime-verified against a live MailPoet
install — same standing caveat as the rest of this plugin.

1.1.1 — Ecosystem quality Phase 2, brick 1 of 13 (this plugin had the
fewest PHPStan level-6 findings of all 12, so it's the first): added
native return types and parameter types to every method that was
missing one (class-debug.php, class-instant-sync.php,
class-scheduled-sync.php, class-sync.php — 32 findings total, all
the two purely mechanical categories: "no return type specified" and
"parameter with no type specified"). Hook-callback parameters whose
real value comes from WordPress/WooCommerce (sync_from_user_id,
sync_from_order, sync_from_entitlement, sync_from_event) are typed
`mixed` at the boundary and cast internally, same as the existing
(int) casts those methods already did — no behavior change, purely
additive type declarations. This plugin is now clean at PHPStan
level 6 in isolation; phpstan.neon itself stays at level 5
ecosystem-wide until all 12 plugins are done (see phpstan.neon's own
comment once that flip happens) so Phase 1's CI gate doesn't break
mid-effort.
NOT runtime-verified against a live MailPoet install (same
disclosure as this plugin's original build — nothing here changes
behavior, only adds compile-time type declarations).
1.1.0 — Registers this plugin as the enhancer for the-self-hosted-self 3.10.0's
new 'email_broadcast' OUS_Integration contract (OUS_Integration::
register('email_broadcast', ['enhancer_class' => 'BHMP_Sync'])) —
Phase 1 of the OSS-integration master plan. Makes explicit, in one
visible place (Debug Tools -> Integration Contracts), what was already
true implicitly: OUS_Campaigns (the-self-hosted-self core) is the always-works
built-in broadcaster, this plugin is what upgrades that to real
MailPoet-backed list sync/automation when installed. No behavior
change to BHMP_Sync/BHMP_ScheduledSync/BHMP_InstantSync themselves.
NOT runtime-verified against a live WordPress+MySQL install this
session; `php -l` clean.

1.0.0 — First cut. bh-crm stays the source of truth for who a contact
is (profile, tags, segments); this plugin's only job is keeping a
MailPoet subscriber list in sync with that, so MailPoet's own
automation engine (list-based triggers) has fresh data to work off
of. Two sync paths: BHMP_ScheduledSync (a daily full resync via
OUS_Jobs, catches everything eventually) and BHMP_InstantSync (a
handful of real-time hooks — user_register/profile_update natively,
woocommerce_order_status_completed and bhm_entitlement_granted as
real WP actions, plus every BH_Event type via the new
'bh_event_emitted' action the-self-hosted-self 3.9.9 added specifically to
make this plugin possible without polling bhcore_events).
Deliberately NOT wired to MailPoet's Automation-builder API or its
transactional-send API — this plugin only manages list membership;
MailPoet's own automations (trigger = "subscriber added to list") are
what actually decide what to send. Keeps this plugin small and keeps
send/template/automation logic entirely inside MailPoet, where it's
actually built for.
Every entry point in includes/class-sync.php is guarded by
class_exists('\MailPoet\API\API') at call time — this plugin loads
and shows up on the ecosystem dashboard even if MailPoet is never
installed, it just does nothing until it is.
NOT runtime-verified against a live MailPoet install this session —
\MailPoet\API\API::MP('v1')'s method names (addSubscriber,
subscribeToList, getLists, unsubscribeFromLists) are from MailPoet's
documented public API, not confirmed against the actual installed
plugin source. Confirm these against the real MailPoet codebase (or
its own developer docs) before relying on this in production; `php -l`
is clean on every file here, but that only proves valid PHP syntax,
not that these calls match MailPoet's real method signatures.
