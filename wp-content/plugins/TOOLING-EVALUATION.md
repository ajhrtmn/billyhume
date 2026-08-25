# Third-party tooling — what to adopt, what to refuse

**Evaluated 2026-08-23** against the real deployment pipeline, not assumptions.

## The test every candidate must pass

Established by reading `.github/workflows/deploy-ftp.yml`: deployment is an **FTP sync of plugin folders, verbatim, from the `stable` branch. No build runs at deploy time.** Runtime is PHP + WordPress only — `composer.json` states outright that it is dev-only and "WordPress itself is loaded from wp-load.php, never Composer's autoloader." Production is a PHP/WASM build that lacks openssl, so extension assumptions must stay conservative.

> **Does the committed artifact run with nothing but PHP and WordPress?**

**Build steps are therefore fine. Runtime dependencies are not.** TypeScript already proves the pattern: `tsc` compiles at dev/CI time, plain `.js` is committed, WordPress enqueues it directly, and the live site needs no Node, no bundler, no autoloader.

## What already exists (don't rebuild it)

- **GitHub Actions CI** — `checks.yml` runs PHPStan + PHPUnit (PHP 8.1) and per-plugin `tsc` on every push/PR to `dev` and `master`.
- **`deploy-ftp.yml`** — release-only, fires on `stable`, whitelists 13 plugin folders.
- **Composer** (dev-only) — PHPStan, PHPUnit, WooCommerce stubs, dead-code detector.
- **npm** (dev-only) — TypeScript 5.6, per-plugin build/watch scripts.

The tooling foundation is good. The gaps are in *coverage*, not *infrastructure*.

## Tier 1 — adopt now

### 1. Playwright — highest value by a wide margin

**Why:** `UX-AUDIT-PLAN.md` scopes ~70 screens × 6 widths × 2 themes ≈ **840 measurements**. That is not hand-work. A bespoke audit harness was written and thrown away twice already in a single session — textbook wheel reinvention.

Playwright gives, in one dev-only dependency: scripted navigation, computed-style assertions, per-breakpoint viewport control, screenshot diffing, console/network capture, and **authenticated sessions via stored state** — which covers the entire "flagged for a session that can log in/out" list (logged-out front end, auth screens, role-based views, portal-as-member, 2FA).

**Deployment impact:** none. Dev/CI only, `node_modules` already gitignored.
**Fit:** slots into the existing `typescript` job in `checks.yml`.

### 2. Stylelint

**Why:** directly enforces "well-formed and organized CSS." Mechanically catches what was measured by hand this session — 200+ hardcoded hex literals bypassing the token system, duplicate selectors (34 in `admin-skin.css`), and it can gate `!important` behind a required explanatory comment. `STYLE-SYSTEM.md`'s four-layer rules are currently prose that only a careful reader enforces; a good chunk becomes a lint rule.

**Deployment impact:** none.

### 3. PHP_CodeSniffer + WordPress-Coding-Standards

**Why:** this entire session was convention drift — patterns that existed and were ignored. PHPCS enforces mechanically what `CONVENTIONS.md` states in prose. The `WordPress.Security` sniffs (escaping, sanitization, nonce checks) matter most given the money and gated-content surfaces.

**Caveat:** WPCS's formatting opinions will conflict with this codebase's existing style. Adopt the **security and correctness sniffs first**, formatting later or never. Start with a baseline so it gates *new* code without a 78k-line reformat.

## Tier 2 — strong, more setup

### 4. `wp-env` + `wp-phpunit`

**Why:** `TESTS.md` already names this as the right next step. DB-backed logic — `BHM_Wallet`'s atomic debit, `BHC_Progress` completion, the purchase ledger — is deliberately untested because a hand-rolled `$wpdb` stub mostly tests the stub. These are the money paths.

**On Docker:** `CLAUDE.md`'s "no Docker" rule governs *runtime/hosting*, not dev tooling. `wp-env` is dev-only and doesn't change what ships.

### 5. Vitest

**Why:** **~9,200 lines of TypeScript with zero tests.** Near-zero config with TS. Same bar as the PHP suites: pure logic with real edge cases (scoring, formatting, state machines) — not coverage theater.

## Tier 3 — optional

**Biome** — one fast binary for lint + format across TS. Real but modest: `tsc` with `strict` + `noUncheckedIndexedAccess` already catches the important class of problems. Adopt if formatting inconsistency becomes a genuine irritation.

## Refuse — and why

| Candidate | Why not |
|---|---|
| **Tailwind** | Fights the four-layer token system and the design brief, which is explicitly about a bespoke visual identity (Streamline Moderne / Dark Deco / composing with light). Utility classes in markup would *increase* context load, not reduce it — the opposite of the goal. |
| **A bundler** (Vite/webpack/esbuild) | `module: none` + `wp_enqueue_script` is the correct WordPress shape. A bundler adds complexity and risks the "runs with nothing but PHP + WP" test for no gain — there are no npm runtime imports to bundle. |
| **Runtime Composer autoloading** | Breaks the deployment model outright. FTP syncs folders; there is no `composer install` on the host. |
| **DI container / Symfony components** | Fights WordPress's hook architecture and adds runtime dependencies. The hook system *is* the composition root. |
| **An ORM** (Eloquent, Doctrine) | Runtime dependency, and `$wpdb` + the `*_Tables` owner classes are the idiomatic layer. WordPress schema conventions don't map cleanly onto an ORM. |
| **HyperPress** | Already surveyed and refused; Datastar is vendored directly instead. Unchanged. |

## The context-load argument

Worth stating plainly, since it is the actual motivation: **Playwright, Stylelint, and PHPCS all reduce context load in the same way** — they convert "things a person must remember to check" into automated gates. Every rule that becomes a lint failure is a rule nobody has to hold in their head, re-derive from docs, or catch in review. That is a larger win than any code-sharing mechanism, because the measured problem in this codebase has never been *writing* the right pattern — it is *remembering* the pattern already exists.

## IMPLEMENTED 2026-08-23

All five adopted. Every one is dev-only; nothing changes what ships.

| Tool | Command | State |
|---|---|---|
| **Playwright** | `npm run audit:ux` | Working. `tests/ux/` — measurement library + logged-out and admin specs. |
| **Stylelint** | `npm run lint:css` | **0 errors**, 18 warnings. |
| **Vitest** | `npm run test:unit` | **24 tests passing.** |
| **PHPCS** (security) | `composer phpcs:changed` | Changed-files gate; non-blocking in CI initially. |
| CSS formatter | `npm run format:css` | `tools/expand-css.js` — one declaration per line. |
| Everything | `npm run check` | build + CSS lint + unit tests. |

## IMPLEMENTED 2026-08-25

The one item this doc left open (`wp-env`/`wp-phpunit`, Tier 2 item 4 above) is adopted. `bh-monetization-woo/tests-integration/` runs `BHM_Wallet::debit()`'s atomicity claim against a real MySQL via `@wordpress/env`, wired into `checks.yml` as `db-integration-tests` (`continue-on-error: true` until proven on GitHub's own runner). Verified locally by deliberately removing the guard clause from the real UPDATE statement and watching the concurrent-write test correctly fail, then restoring it.

Also added the same day, not from this doc's original list but the same "convert a remembered check into a machine-enforced one" motivation: a Storybook visual-regression suite (Playwright screenshot diff against committed baselines), a daily canary confirming the self-update mechanism's GitHub URLs still resolve, and a narrow docs-drift check (a plugin's `Version:` header vs its own `CHANGELOG.md`'s newest entry — found real drift on 4 of 14 plugins immediately). See each tool's own `tools/*.sh` header for reasoning, and `OPEN.md`'s "Tooling" section for current status.

**CI** (`checks.yml`) now runs PHPStan (level 6 blocking, level 7 tracked non-blocking), PHPUnit, PHPCS (changed files, non-blocking), five `tsc` builds, Stylelint, Vitest, and (2026-08-25) a real-database integration suite via `wp-env`/`wp-phpunit` and a Storybook visual-regression job — both `continue-on-error: true` pending their first clean runs on GitHub's own infrastructure. The logged-out `ux-audit` job that used to sit here gated `if: false` was moved to its own workflow, `storybook-audit.yml`, once it became clear it needed no provisioned instance — it points at the real live site instead.

### What the tools found immediately

- **Playwright, first run on the logged-out front end:** `button.wp-block-search__button` measures **4.2:1** (needs 4.5) at every width — the theme's search button fails AA. Plus real ≤782px touch targets under 44px: `.oust-nav-toggle` 40×40, `.oust-card-readmore` 61×18, `.oust-site-brand` 202×30. Exactly the front-end gap `DESIGN-CRAFT.md` predicted, now measured rather than asserted.
- **Stylelint:** 2 genuine deprecations (`word-break: break-word` → `overflow-wrap`), fixed. 66 auto-fixable modernizations applied.
- **CSS readability:** ~470 packed single-line rules expanded to one declaration per line across our 19 stylesheets. Verified semantically identical — all tokens and contrast ratios measured unchanged before/after.

### Tuning decisions, each measured not guessed

- **Stylelint formatting rules disabled** except one-declaration-per-line. `no-duplicate-selectors` demoted to *warning*: the flagged cases are deliberate progressive layering (base border, then the documented glow treatment), not bugs.
- **`WordPress.DB.PreparedSQL*` disabled.** 614 of its findings are table-name interpolation, and a table name cannot be bound as a prepared-statement parameter in MySQL. The actual user data already uses `%d`/`%s`. Leaving it on would mean 614 permanent false positives drowning real signal.
- **Custom helpers registered** so WPCS stops mis-reporting correct code: `verify_nonce_and_cap()` (33 call sites) as a nonce-verification function.
- **PHPCS scoped to changed files.** Whole-tree adoption surfaces ~1,200 pre-existing low-severity findings (an un-unslashed nonce, `esc_url_raw` without `wp_unslash`) in code otherwise escaping correctly. A gate that fails on day one gets disabled.
- **Playwright exclusions**, each earned by a real false positive on the first runs: collapsed elements (`clientHeight === 0`) aren't clipped; overflow caused only by absolutely-positioned decoration (the hero starburst, deliberately bleeding under `overflow: hidden`) isn't clipped; inline links inside text are exempt from the 44px target minimum per WCAG 2.5.8.

### The gate that found what review kept missing (2026-08-24)

`tools/check-accent-on-tint.js`, wired into `npm run check`.

One CSS pattern — accent-coloured text on a `color-mix()` tint of that *same* accent — produced **five** separate AA failures: the `.bhy-alert-*` variants, the unread notification card, the course price badge at **3.23:1** (the price), a course order-status pill, and the datepicker's "today" cell.

Nothing caught them. Stylelint cannot express "these two values are related", and a reviewer reads `color: var(--bh-accent)` beside `background: color-mix(..., var(--bh-accent) 14%, ...)` as obviously coherent — which is exactly why it kept recurring. The two never separate, because they are the same hue at similar lightness.

The check is deliberately narrow: the rule must set both `color` and a background, and both must reference the **same** accent custom property. That is the shape all five took, and it keeps the output small enough to read.

**Calibration matters as much as detection.** A first attempt to generalise it — treating a token and its own `-bg` sibling as the same hue — flagged six rules that are all fine. `--bh-success` on `--bh-success-bg` is a pair someone deliberately chose, and it measures **6.23:1 in dark, 10.66:1 in light**. Those were measured *before* being "fixed", which is the only reason they were not broken in service of a checker.

The real shape is narrower: a background deriving a **`color-mix()` tint of the very token used for the text**. Nobody chose that contrast — it fell out of the mix percentage. The check is verified in both directions: it catches that shape and ignores the designed `-bg` pair.

**Two lessons, both earned the hard way in one sitting:**

1. **A green checker you have never seen fail proves nothing.** The first version resolved `ecosystem-plugins.txt` entries as paths when they are folder *names*, so it scanned zero files and passed. Verified by deliberately reintroducing the pattern and watching it not fire.
2. It now **exits 2 rather than 0** when no roots or no stylesheets resolve. A tool that cannot find its inputs must not report success.

On the fixed run it immediately found the two instances a manual sweep had missed.

### One incident worth recording

An early run of the CSS formatter used a raw shell glob and reformatted **WooCommerce's** vendor stylesheets. WooCommerce is gitignored, so that was not revertible. The output is semantically identical and the site is healthy, and a WooCommerce update will overwrite those files anyway — but vendor code should never have been touched. `tools/expand-css.js` now hard-refuses any path outside the ecosystem's own 14 plugins, regardless of what it is handed.

## Suggested order

1. **Playwright** — unblocks the UX audit, which is the largest queued body of work.
2. **Stylelint** — cheap, immediate, enforces a stated goal.
3. **PHPCS** (security sniffs + baseline) — protects the money and gated-content surfaces.
4. **Vitest** — closes a 9,200-line blind spot.
5. **wp-env/wp-phpunit** — the heaviest, and the one with the deepest payoff on wallet/entitlement logic.

Each is independently adoptable, dev-only, and CI-native. None touches what ships.
