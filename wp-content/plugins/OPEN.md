# OPEN — what's unfinished

Only open items live here. Finished work is in each plugin's `CHANGELOG.md` and in git. Ordered roughly by value.

## Bugs

None known. The list found by reading the code in November 2026 was fixed in bh-contest 3.16.3, bh-courses 0.17.3, bh-crm 2.4.29 and bh-monetization-woo 0.7.3 (see their changelogs). The contest fixes are covered by tests but have not been tried on a real contest yet: try a judged contest end to end (scores, reveal, Discord post, winner emails) before relying on it.

## Leftovers from the November 2026 rescope

- **Wallet, referrals and the storefront** in bh-monetization-woo were built for the removed streaming plugin (pay-per-play credit). Nothing on the site spends wallet credit now. Remove them if Billy doesn't plan to use them.
- **Words not yet in `words.php`:** the emails, the certificate PDF, a few rarely seen notices and some labels inside wp-admin forms (e.g. "base" / "bonus for submitting" next to the vote fields) still have their wording in the PHP files.
- **Old database tables** from the removed plugins (streaming, registry, live, feedback, social, DMCA notices) are still in the database. Harmless; drop them only after a backup.

## Code cleanup still open

- **bh-contest loads Howler from cdnjs**; vendor it into the plugin so the player doesn't depend on a CDN.
- **Inline `<script>`/`<style>` blocks that splice in PHP values**, led by the Design Suite scripts in `class-style-gallery.php`, the contest reports widget, and `class-ui.php`'s swatch script. Move each to a file with its data passed as JSON, when that screen is next touched.
- **Long render functions** (`render_step()`, `render_controls()`, `OUS_Debug::render()`, the course/contest metaboxes). Split when the screen is next changed.
- **Hardcoded hex colors** in plugin stylesheets (`kanban-board.css`, `bhm/frontend.css`, `storefront.css`, …) that don't follow the design tokens.
- **PHPCS** is advisory (~1,200 low-severity findings) and **PHPStan level 7** has ~486 findings, mostly unhandled `|false`/`|WP_Error`.

## Look and feel (see `DESIGN-DIRECTION.md`)

- Depth steps (three shadow levels), front-end alert style and focus states, and one shared "moment that matters" treatment.
- No loading/skeleton states yet; many state changes are full page reloads.
- UX passes not yet run on the live contest voting/reveal flow and the front end in both themes.

## Blocked / needs someone with live-site access

- **GitHub Updates "Update now"** hasn't been click-tested end to end on a preview host (the daily canary only checks the URLs resolve).
- **Course Sessions calendar blank on the live site** — fine locally; needs the live page's browser devtools to see whether the bundled FullCalendar 404s or gets rewritten by an optimizer.

## Verification habits worth keeping

- Measure, don't eyeball: contrast, overlap, clipping (`scrollHeight` vs `clientHeight`), horizontal overflow.
- Bump the plugin version when testing changed CSS/JS, or the browser keeps the old cached copy.
- Say plainly when something was reasoned through rather than run.
