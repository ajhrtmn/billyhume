# AUDIT — Core: identity, events, roles, audit log (task 1 of 16)

**Scope:** `own-ur-shit/includes/class-identity.php` (`BH_Identity`), `class-event.php` (`BH_Event`), `class-roles.php` (`OUS_Roles`), `class-audit.php` (`OUS_Audit`), plus their activator/schema files.
**Date:** 2026-07-25. **Model:** Opus. **Dimension:** code quality (DRY/SOLID/naming/comments), not UX.
**Caveat:** no live PHP/MySQL execution environment available — static analysis only. Every finding below was read at its actual file:line, not grep-matched; a grep hit alone was treated as a lead, not a finding.

## Findings

**Moderate**

1. **`class-roles.php:4-17` — docblock now contradicts the code.** The class docblock states it registers "granular capabilities — not new WordPress ROLES (a full role-assignment admin UI is separate, roadmapped scope)." But `ensure_manager_role()` (113-118, called from `activate()`) now calls `add_role('bhcore_studio_manager', 'Studio Manager', …)` — a real custom role — and the inline comment at 98-99 celebrates this. The "just plumbing, not role assignment" claim has drifted: still no assignment UI, but no longer capability-only. Fix: update the docblock to acknowledge the one baseline role it now creates.

2. **`class-audit.php:110-124` vs `133-155` — `log()` and `log_diff()` duplicate the entire write body** (actor resolution + an identical 7-key `$wpdb->insert` + `maybe_prune()` call). Extract a private `write()` both call into.

3. **`class-audit.php:121` vs `class-event.php:95`/`141` — timestamp basis disagrees.** `OUS_Audit` writes `created_at` with `current_time('mysql')` (site-local time); `BH_Event` writes with `current_time('mysql', true)` (UTC). Any future correlation of an audit-log entry to an event row will be silently off by the site's UTC offset. Standardize both on UTC.

**Minor**

4. **`class-event.php:43` — `SCHEMA_VERSION` constant is dead code** (grep-confirmed the sole occurrence is its own declaration). The live versioning mechanism is the per-event-type `v` parameter. Remove the unused constant.
5. **`class-event.php:166-184` — the two INSERT branches duplicate the full 10-column list/values array**, differing only in `INSERT` vs `INSERT IGNORE` and the trailing dedup slot. The dedup logic itself is correct — this is pure DRY debt, not a bug.
6. **`class-identity.php:105-115` — `BH_Identity` hardcodes the `bhcore_events` table name and column list** for a debug-only reverse lookup, duplicating schema knowledge that `BH_Event` already owns. Delegate to a `BH_Event` accessor so the schema has exactly one owner.
7. **`class-identity.php:42-44` vs `65-67` — duplicated cookie read/sanitize idiom.** Trivial, low priority.

## Confirmed good (specifically re-verified, not assumed)

- **`OUS_Audit` has a real bounded-growth story** — row-count cap (MAX/KEEP thresholds), a static+transient throttle, oldest-by-id deletion — meeting this ecosystem's own stated standard (see `OUS_DebugLog::maybe_trim()` as the reference pattern this should, and does, follow).
- Both `bhcore_events` and the audit table carry real schema versioning with upgrade paths (audit at `DB_VERSION` 1.0 + `maybe_upgrade()`; events under `bhi_db_version` 1.8 with a documented changelog).
- The NULL-vs-`''` dedup handling for events is correct and consistently documented on both the writer (`class-event.php:147-184`) and the schema (activator:191-195) side.
- `BH_Event::emit()` is genuinely non-blocking — it enqueues to `OUS_Jobs` rather than performing a synchronous write on any hot path (e.g. a play/vote beacon), matching the ecosystem's stated architectural rule.

## Priority build-order

1. Fix `OUS_Roles` docblock — actively misleading to a future reader, ~2 min fix.
2. Unify `OUS_Audit`/`BH_Event` timestamp basis to UTC.
3. Extract `OUS_Audit::write()` to remove the `log()`/`log_diff()` duplication.
4. Delete the dead `SCHEMA_VERSION` constant in `class-event.php`.
5. Opportunistic DRY cleanups (#5–#7) — low priority, bundle with any other pass through these files.
