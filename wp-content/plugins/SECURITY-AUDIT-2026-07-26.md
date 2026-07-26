# URL / Endpoint / Query-Parameter Authorization Audit — 2026-07-26

**Scope:** every `register_rest_route(`, `add_action('wp_ajax_*')`, `add_action('admin_post_*')`, `add_rewrite_rule(`/`get_query_var(`, nonce usage, and file-serving code across all `bh-*` and `own-ur-shit` plugins under `wp-content/plugins/`. Plugins covered: `own-ur-shit`, `bh-contest`, `bh-courses`, `bh-crm`, `bh-streaming`, `bh-monetization-woo`, `bh-registry`, `bh-feedback`. (`advanced-media-offloader`, `query-monitor`, `woocommerce` are third-party and out of scope; no other `bh-*` directories exist.)

**Method:** grepped every plugin for the six pattern categories in scope, then read the full body of every REST callback, `admin_post_*`/`wp_ajax_*` handler, and rewrite/query-var consumer that touches an ID, plus every file-serving code path. This is a from-scratch pass — no prior findings were assumed.

**Headline result:** this codebase is unusually well-hardened against IDOR/CSRF for a project this size. Nearly every handler that accepts an object ID from the request explicitly re-derives ownership server-side before acting (`post_author === current_user_id`, `wc_subscription->get_user_id() === current_user_id`, a dedicated `owns()` helper, a jam session's `host_user_id`, etc.), and state-changing `admin_post_`/AJAX actions consistently pair a capability check with a `wp_verify_nonce()`/`check_admin_referer()` call — frequently with the object ID baked into the nonce action string itself (e.g. `'bhcrm_card_add_fix_' . $card_id`), which additionally defeats a stolen/replayed nonce from a different object. Numerous inline comments in the code explicitly document prior audit passes ("Audit fix (2026-07-25)", "QA fix") that already closed IDOR/CSRF gaps of exactly the kind this audit was asked to look for — the codebase shows evidence of having already been through at least one focused security-hardening pass before today.

No CRITICAL or HIGH findings were identified. Two LOW-severity hardening notes are recorded below — neither is an exploitable vulnerability today, but both are worth a deliberate decision rather than leaving implicit.

## Summary Table

| File:Line | Severity | Description |
|---|---|---|
| `bh-streaming/includes/class-jam.php:75-88` (`rate_limited()`) | LOW | Jam invite-code brute-force throttle is per-user (via transient keyed on `get_current_user_id()`), not per-IP — a botnet of many logged-in accounts could still enumerate 6-char codes faster than intended, though the code space (~1 billion) makes this impractical in practice. |
| `bh-crm/*` admin_post handlers (`class-card-log.php`, `class-subtasks.php`, `class-projects.php`, `class-segments.php`, `class-tags.php`, `class-notes.php`) | LOW (by design, not a bug) | All gated by the single coarse capability `bhcore_manage_crm` with no per-project/per-card ownership check — any user holding that capability (editor role, "Studio Manager" role) can read/modify every CRM project, card, and person, not just ones they're assigned to. This is consistent with a single-tenant internal CRM tool (all staff share the whole CRM) rather than a multi-tenant app, so it is flagged as a design note for confirmation, not a vulnerability. |

No CRITICAL, HIGH, or MEDIUM findings were confirmed. Detail on each LOW item follows, plus a short account of the highest-risk surfaces that were checked and found correctly guarded (so a future re-audit knows what was already verified).

---

## LOW-1: Jam invite-code guessing is throttled per-user, not per-IP

**File:** `bh-streaming/includes/class-jam.php:75-88`

**The surface:** `POST /wp-json/bhs/v1/jam/{code}/join` — any logged-in user can attempt to join a Jam session by 6-character invite code (`ABCDEFGHJKMNPQRSTUVWXYZ23456789` charset, no ambiguous chars). The code's own comment (lines 78-88) already documents the reasoning: "join has no other secret gating it once you're logged in," so the invite code itself is the only real barrier.

**Current code:**
```php
private static function rate_limited($action, $limit = 20, $window = 60) {
    $key = 'bhs_jam_rl_' . $action . '_' . get_current_user_id();
    $count = (int) get_transient($key);
    if ($count >= $limit) return true;
    set_transient($key, $count + 1, $window);
    return false;
}
```
This correctly throttles a single account to 20 join attempts/minute. It does not throttle by IP, so an attacker controlling many accounts (or many freshly-registered throttle-free accounts) could parallelize guesses across accounts to exceed the effective per-attacker rate.

**What a correct check would need:** an additional IP-keyed (or IP+action-keyed) rate limit alongside the existing per-user one, so the guess rate is bounded per network origin as well as per account. Given the code space (~1 billion) this is a defense-in-depth improvement, not an urgent fix — a session also auto-expires after `STALE_AFTER_SECONDS` (6 hours), further limiting the guessing window.

## LOW-2: `bhcore_manage_crm` is an all-or-nothing capability across all CRM records

**Files:** `bh-crm/includes/class-card-log.php` (lines 446-499), `bh-crm/includes/class-subtasks.php`, `bh-crm/includes/class-projects.php` (lines 895-963), `bh-crm/includes/class-segments.php`, `bh-crm/includes/class-tags.php`, `bh-crm/includes/class-notes.php`

**The surface:** every `admin_post_bhcrm_*` handler (card fixes/feedback/uploads, subtasks, project create/save/delete, segments, tags, notes) gates on `current_user_can('bhcore_manage_crm')` and nothing more specific — e.g.:
```php
public static function handle_add_fix() {
    if (!current_user_can('bhcore_manage_crm')) wp_die('Not allowed.');
    $card_id = (int) ($_POST['card_id'] ?? 0);
    if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'bhcrm_card_add_fix_' . $card_id)) wp_die('Bad nonce.');
    self::add_fix($card_id, self::parse_timestamp($_POST['timestamp'] ?? ''), wp_unslash($_POST['note'] ?? ''));
    ...
```
Any user with `bhcore_manage_crm` (granted to the Editor role and the plugin's own "Studio Manager" role per `bh-crm`'s comments elsewhere) can act on *any* project/card/person's data by simply changing `card_id`/`project_id` in the POST body — there is no check that the requesting user is the project's owner or an assignee.

**What this would need if it's not intended behavior:** a per-project or per-card assignment check (e.g. "is this user a collaborator on this project") layered under the existing capability gate, the same way `bh-contest`'s `replace_audio()`/`edit_details()` layer a `post_author === current_user` check under `is_user_logged_in()`.

**Why this is LOW and not HIGH:** the CRM is explicitly an internal, staff-facing tool (not exposed to end users/customers), and every plugin in this ecosystem that DOES expose data to arbitrary end users (contest submissions, playlists, subscriptions, wallet, Jam sessions) was independently checked and found to enforce real per-object ownership, as detailed below. This finding is recorded so the design intent ("all CRM staff share the whole CRM, by design") is a documented decision rather than an unexamined default — worth a one-line confirmation from whoever owns the CRM's access model, not a code change on its own.

---

## Surfaces checked and confirmed correctly authorized (no fix needed)

For completeness/traceability, the following high-risk, ID-bearing endpoints were read in full and found to correctly verify ownership and/or nonce before acting:

- **bh-contest** `/vote`, `/submit`, `/submissions/replace-audio`, `/submissions/edit-details` (`class-api.php`) — `replace_audio`/`edit_details` explicitly check `(int) $post->post_author === $uid || current_user_can('manage_options')` before touching a submission; `/vote` re-validates the submission belongs to the target contest and is published before allowing a new vote.
- **bh-streaming** `/playlists/{id}/tracks`, `/playlists/{id}/share`, `/playlists/{id}/unshare` (`class-playlists.php`) — all three route through a shared `owns($playlist_id, $uid)` helper checking `post_author`; `/playlists/{id}` GET distinguishes public/owner/forbidden correctly.
- **bh-streaming** Jam session control routes (`class-jam.php`) — `push_host_state`, `kick`, `approve`, `deny` all check `(int) $session['host_user_id'] === $uid`; `get_state`/`vote_skip` check `is_participant()`; pending-joiner identities are only ever returned to the host.
- **bh-monetization-woo** `/wallet` (`class-frontend.php`) — reads only `get_current_user_id()`'s own balance/ledger, no ID parameter accepted at all.
- **bh-monetization-woo** `admin_post_bhm_manage_subscription` (`class-frontend.php:330-361`) — explicitly checks `$subscription->get_user_id() === $user_id` before allowing pause/resume, with an inline comment calling out this exact ownership-check requirement.
- **bh-monetization-woo** `admin_post_bhm_redeem_gift` / `admin_post_bhm_revoke_entitlement` (`class-gifts.php`, `class-crm-integration.php`) — gift redemption is gated by possession of the (secret, per-code) nonce; revoke is gated by `bhcore_view_crm_sensitive` + nonce.
- **bh-registry** `/artists/{id}`, `/artists/{id}/feed-url` (`class-api.php`) — both filter to `status = 'active'` server-side; pending/rejected artist records are never exposed regardless of ID guessed.
- **own-ur-shit** `/elements/*`, `/studio/{context_type}/{context_id}` (`class-element.php`, `class-studio.php`) — every route requires `current_user_can('manage_options')`; these are admin-only page-building tools, not end-user-facing.
- **own-ur-shit** `/reports` REST + `admin_post_bhi_submit_report` (`class-reports.php`) — both require login, are rate-limited per user, and always record `reporter_user_id = get_current_user_id()` (never client-supplied).
- **own-ur-shit** `ajax_snippet` file-serving handler (`class-codebase-docs.php:229-260`) — admin-only (`manage_options`) + nonce, and uses `realpath()` + prefix-check against the plugins root to defeat path traversal before ever calling `file()`.
- **bh-courses** quiz/progress AJAX handlers (`class-progress.php:523-602`) — all operate exclusively on `get_current_user_id()`'s own progress rows; no student/user_id parameter is accepted from the client. The one place another user's progress CAN be edited (`class-progress-admin.php`'s `maybe_handle_override()`) is gated by an instructor/admin capability plus nonce, and only ever calls the same `mark_step_complete()` a real student completion would call.

## Notes on what was intentionally out of scope for a "fix" recommendation

- `bh-crm`'s `bhcore_manage_crm`-wide access (LOW-2 above) is flagged as a documented design decision to confirm, not a code change, since changing it would be a real feature/permissions-model decision (e.g. introducing per-project assignment) rather than a bug fix.
- `bh-registry`'s `bhr/v1` namespace is deliberately fully public/unauthenticated (CORS `*`) by design, documented in the code as intentional (a cross-origin artist directory API with no credentialed-request risk since nothing there relies on cookie auth) — not re-flagged here as it's a documented, reasoned decision rather than an oversight.
