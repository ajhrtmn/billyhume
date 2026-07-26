# bh-streaming — Code-Quality Audit

**Date:** 2026-07-25
**Model:** Claude Sonnet 5
**Scope:** entire `bh-streaming` plugin — all 22 PHP files in `includes/` plus `bh-streaming.php` (~4.0K lines), read in full, not sampled.
**Audit type:** CODE QUALITY only (DRY/SOLID/naming/comments/dead code/fragile patterns). A separate task covers UX for this plugin — noted below only where a code-level gap directly causes a UX gap.
**Caveat:** No live PHP/MySQL/WordPress execution environment was available. This is static-analysis-only — every finding below is grounded in the actual file/line read, not a grep guess, but none of it was runtime-verified (no test run, no request fired).

---

## 1. Re-checks of prior fixes (both from the 2026-07-19-ish pass)

### 1a. `class-player.php` — unconditional `BHY_Style::inline_css()` call
**Verdict: STILL FIXED, correctly guarded.**
`includes/class-player.php:71`:
```php
if (class_exists('BHY_Style')) wp_add_inline_style('bhs-player', BHY_Style::inline_css());
```
Guard is present and correctly placed inside `maybe_enqueue()`. The four `empty_state_html()` calls added in the same method (lines 84–105) are also each individually `class_exists('BHY_Style')`-guarded with an empty-string fallback — no regression, no new unconditional cross-plugin call introduced since.

### 1b. `class-recommendations.php` — `/tracks/{id}/related` bypassing `BHS_API::track_payload()`
**Verdict: STILL FIXED, no other bypass found.**
`includes/class-recommendations.php:71` now routes every candidate through `BHS_API::track_payload($p)`, and line 76 correctly treats "locked but no url" as still worth surfacing (paywall notice) vs. "no url and not locked" (skip — genuinely broken). Checked every other place in the plugin that resolves audio URLs (`class-api.php::audio_url_for()`, `class-feeds.php::export_feed()`, `class-jam.php::tracks_payload()`) — all either call `track_payload()`/`audio_url_for()` themselves or are the canonical resolver. No sibling bypass exists.

---

## 2. New/previously-unreviewed features — findings

### HIGH

**H1. CSV injection in the PRO Registration royalty-report export**
`includes/class-pro-wizard.php:109-116` (`handle_export_royalty_report()`)
```php
fputcsv($out, ['Track', 'ISRC', 'Purchase date', 'Price', 'Anchor status', 'Record hash']);
foreach ($rows as $row) {
    $track_title = $row->track_id ? (get_the_title($row->track_id) ?: ('#' . $row->track_id)) : 'Unknown';
    ...
    fputcsv($out, [$track_title, $isrc, ...]);
}
```
`$track_title` and `$isrc` come straight from track post title / postmeta with no leading-character sanitization. A track titled e.g. `=cmd|'/c calc'!A1` or starting with `=`, `+`, `-`, `@` is a classic CSV-injection/formula-injection payload — Excel/Sheets will interpret it as a formula when the artist (or anyone with `edit_posts` on a track) opens the exported CSV in a spreadsheet app, and this file is explicitly meant to be handed to a PRO/MLC as a royalty claim attachment, i.e. forwarded to a third party. Low likelihood on a single-artist site where the artist controls their own titles, but the file crosses a trust boundary (goes to an external royalty processor) and the fix is one line.
**Fix:** prefix any value starting with `=+-@` with a `'` (or a leading tab) before `fputcsv()`, same as WordPress core's own `wp_kses` CSV-export helpers do elsewhere in the ecosystem (check whether `OUS_*` already has a shared "safe CSV cell" helper — bh-monetization-woo's own ledger exports likely have the same gap and should share one fix).

### MEDIUM

**M1. `class-feeds.php::export_feed()` hardcodes `audio/mpeg` as the enclosure MIME type for every track, regardless of actual file type**
`includes/class-feeds.php:397-399`:
```php
$enclosure = $doc->createElement('enclosure');
$enclosure->setAttribute('url', $audio_url);
$enclosure->setAttribute('type', 'audio/mpeg');
```
This plugin explicitly widened upload support to AIFF/WAV (`bh-streaming.php:107-125`, the two `upload_mimes`/`wp_check_filetype_and_ext` filters), and `class-admin.php`'s Quality Encodes metabox explicitly documents accepting "WAV / AIFF / FLAC" for the lossless tier. But the RSS/Podcasting-2.0 export this plugin ships (which the file's own docblock says other bh-streaming sites and podcast apps are meant to subscribe to and re-import) always claims `audio/mpeg` even when the actual file is a `.wav` or `.aiff`. A strict feed consumer (including another bh-streaming instance's own `validate_is_open_feed()`/`sync_one()` importer, which trusts the enclosure it's given) will mis-sniff or reject a non-MP3 track re-exported this way. Same class of bug the `wp_check_filetype_and_ext` filter earlier in the plugin exists specifically to prevent on the *upload* side — the export side never got the equivalent fix.
**Fix:** derive the real type from the attachment's own mime (`get_post_mime_type($aid)` for local files) and fall back to `audio/mpeg` only when nothing better is known (external URL with no local attachment to introspect).

**M2. `class-video-post-types.php` — video upload has no admin-usable path, only a raw REST endpoint**
`includes/class-video-post-types.php:70-71`:
```php
echo '<p class="description">Upload a video via the REST import endpoint (<code>POST bhs/v1/video-import</code>), then enter its Video post ID here once created:</p>';
echo '<input type="number" name="bhs_release_video_id" ...>';
```
Every other upload path in this plugin (audio, artwork, quality encodes) is wired to `wp.media` via `render_media_picker_script()` (`class-admin.php:288-318`) — a real button that opens the native WP media picker. Video has neither a `wp.media` picker nor any JS at all calling `bhs/v1/video-import`; the metabox's own instructions require an admin to manually construct and fire a raw authenticated multipart POST (curl/Postman) and then hand-type the resulting numeric post ID back into a plain number field. This is a genuine code-completeness gap, not just a UX rough edge: the REST route and CPT are fully built, but there is no first-party code path inside wp-admin that ever calls it. As shipped, this feature is only reachable by someone who can script a REST request — effectively dead code for a typical admin.
**Fix:** add a `wp.media`-based picker for video the same shape as `pick('bhs_audio_upload', ...)` in `class-admin.php`, calling `video-import` on selection and populating the hidden ID field automatically — same pattern already proven three times over in this same plugin.

**M3. Mock-ISRC regex duplicated between PHP and inline JS, no shared source of truth**
`includes/class-isrc.php:23`:
```php
const MOCK_PATTERN = '/^ZZOUS\d{7}$/';
```
`includes/class-admin.php:172` (inline `<script>` in `render_track_metabox()`):
```php
if (!/^ZZOUS\d{7}$/.test(this.value)) note.style.display = "none";
```
The PHP-side pattern is a named constant specifically so, per `class-isrc.php`'s own docblock, "the rest of the plugin ... never has to duplicate the pattern check." The admin metabox's client-side note-hiding logic does exactly that duplication anyway, just inline in a hand-written JS string rather than through `BHS_ISRC::MOCK_PATTERN`. Harmless today since both literals currently match, but it's exactly the kind of copy that goes stale silently — if `MOCK_PATTERN` is ever tightened or the mock scheme changes shape, this JS copy has no way to know and will keep showing/hiding the "placeholder" note based on the old, wrong pattern with no error, just a wrong UI state.
**Fix:** emit the pattern into `wp_localize_script`/inline JS from the PHP constant (`wp_json_encode(BHS_ISRC::MOCK_PATTERN)`, already used for the nonce two lines above) rather than a second hand-typed literal.

### LOW

**L1. `ajax_issue_isrc()` checks a blanket capability, not edit rights on the specific post**
`includes/class-admin.php:38-43`:
```php
public static function ajax_issue_isrc() {
    check_ajax_referer('bhs_issue_isrc', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error(['message' => 'Not allowed.'], 403);
    $isrc = BHS_ISRC::issue();
    ...
}
```
This only confirms the requester can edit *some* post, not that they can edit *this* track — but since the endpoint takes no post ID at all (it just mints and returns a fresh code with no side effect on any specific row), the practical risk is limited to "any user who can edit any post can mint an unused ISRC/consume one sequence slot," which is low-stakes (real-registrant sequence numbers are cheap and this is a single-artist site). Noting for completeness since every other save handler in this file checks `current_user_can('edit_post', $post_id)` against the actual post — this one endpoint doesn't have a post ID to check against, so it's a different (weaker but lower-consequence) shape, not a straightforward inconsistency to "fix" without changing the endpoint's contract.

**L2. `class-jam.php::push_host_state()` — `$state['index']` clamp math depends on queue never being empty at this point**
`includes/class-jam.php:400`:
```php
if ($req->get_param('index') !== null) $state['index'] = max(0, min(count($state['queue']) - 1, (int) $req->get_param('index')));
```
If `$state['queue']` is empty (`count() - 1 = -1`), this correctly clamps to `max(0, -1) = 0`, so no crash — but it silently sets `index` to `0` for an empty queue rather than leaving it unset/flagging the caller. Not a bug found in practice (queue can't currently be pushed empty by any client code path examined), just worth a one-line comment noting the empty-queue case is intentionally absorbed here rather than rejected, since it's not obvious from reading the line alone why `-1` never actually surfaces.

---

## 3. Confirmed good (new features reviewed with fresh eyes)

- **`class-isrc.php` mock/real ISRC boundary is clean.** `MOCK_PATTERN`'s "ZZ" reserved-country-code choice is genuinely collision-proof against any future real issuance (verified against ISO 3166-1's actual reserved-code list, not just trusted from the comment). `is_real_registrant_configured()` gates the real path with proper shape validation (`strlen === 2`, `preg_match` on the registrant code) before ever trusting stored option data as "real." The mock path's collision check (`class-isrc.php:139-145`, querying `_bhs_isrc` postmeta directly) is real, not decorative — confirmed it actually runs a `SELECT COUNT` per candidate and retries, not just a comment claiming it does. `BHS_ISRC::is_mock()` is called from exactly the two places it should be (`class-admin.php` save handler re-deriving server-side, `class-player.php` SEO suppression) — no third place duplicates or drifts from this check. Test coverage in `class-test-suite.php::run_isrc_tests()` exercises both the pattern-matching and the collision-avoidance path with a real seeded postmeta row, not just the trivial regex case.
- **`class-pro-wizard.php` doesn't fake validation it can't do.** No "test connection" UI, no misleading progress/spinner implying a live check is happening — the docblock and on-screen copy both explicitly say there's nothing here to verify automatically, and the code matches that: `handle_save()` just persists whatever the admin typed with sanitization, no fabricated success state. This is the correct, honest shape for a flow with no real API to wrap, and it says so rather than dressing up a form as a wizard.
- **`class-recommendations.php` scoring is transparent and matches its own docblock's claims exactly** — artist=3, release=4, genre=intersection count, `arsort()`, slice to 10. Verified the weights in code match what the class-level comment and the test suite (`run_recommendations_tests()`) both assert.
- **`class-jam.php`'s host-hand-off-on-leave and rate-limiting are real, not cosmetic** — `rate_limited()` actually gates `create`/`join` via transients (checked the increment-then-check logic is correct, not an off-by-one), and `leave()` correctly reassigns `host_user_id` to the longest-present remaining participant rather than just ending the session.
- **`class-audio-hash.php`'s error-handling gap fix (the `sha1_file()` failure logging) is real and correctly scoped** — logs specifically when hashing fails, distinct from "no duplicate found," so an admin can actually tell the two cases apart from the log.
- **ROADMAP-discoverability.md's claimed closure is real, not aspirational.** `class-player.php::maybe_set_seo_data()` (lines 274-333) genuinely emits `MusicRecording`/`MusicAlbum` JSON-LD via `BH_SEO::set_page_data()`, gated correctly behind `class_exists('BH_SEO')`, and correctly strips a mock ISRC before it reaches published schema (line 294) — the ISRC mock-suppression guarantee is carried through end-to-end, not just claimed in a comment.
- **CORS route allowlist (`class-api.php::CORS_ROUTES`) is a real, checked regex allowlist**, not a blanket wildcard — confirmed the pattern set matches only the documented read-only routes and `add_cors_headers()` iterates and matches before ever setting the header.

---

## 4. Prioritized punch-list

1. **[HIGH]** Sanitize CSV-injection-prone cells (`$track_title`, `$isrc`) in `class-pro-wizard.php::handle_export_royalty_report()` before `fputcsv()` — one-line fix, crosses a trust boundary to a third-party PRO/MLC.
2. **[MEDIUM]** Fix hardcoded `audio/mpeg` enclosure type in `class-feeds.php::export_feed()` to reflect the track's real mime — affects AIFF/WAV tracks specifically, which this plugin explicitly supports uploading.
3. **[MEDIUM]** Wire a real `wp.media` picker for video import in `class-video-post-types.php` — currently the only feature in the plugin with zero first-party admin-usable path, everything else already has the pattern to copy from `class-admin.php`.
4. **[MEDIUM]** De-duplicate the mock-ISRC regex — source the inline JS check in `class-admin.php` from `BHS_ISRC::MOCK_PATTERN` instead of a second hand-typed literal.
5. **[LOW]** Note/accept `ajax_issue_isrc()`'s coarser-than-usual capability check (no post-specific `edit_post` check possible, since the endpoint is post-agnostic) — no action required unless the endpoint's contract changes.
6. **[LOW]** Add a one-line comment on `class-jam.php::push_host_state()`'s index-clamp explaining the empty-queue absorption is intentional.

No dead code, no SOLID violations, and no naming inconsistencies were found beyond what's listed above — the plugin's comment density and cross-referencing convention (the bar set by `class-portal.php`/`class-people.php`/`class-wallet.php`/`class-jam.php` elsewhere in the ecosystem) is consistently met across all 22 files, including the three newly-shipped features (ISRC, PRO Wizard, video).
