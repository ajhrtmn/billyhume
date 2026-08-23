<?php
if (!defined('ABSPATH')) exit;

/**
 * OUS_TestRunner suite for bh-crm — same convention as the rest of the
 * ecosystem's own class-test-suite.php files. This plugin had ZERO test
 * coverage before this pass. Covers BHCRM_Segments::sanitize_conditions()
 * (the server-side validation that stands between a raw $_POST array and
 * what actually gets run as a user filter), BHCRM_Export::csv_safe()
 * (the CSV-injection guard), and BHCRM_Subtasks' pure tree-manipulation
 * helpers (find_node()/children_at()/total_node_count()).
 */
class BHCRM_TestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register($suites): array {
        $suites['bh-crm'] = ['label' => 'BH CRM', 'callback' => [self::class, 'run']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run(): array {
        if (!class_exists('OUS_TestRunner') || !class_exists('BHCRM_Segments')) {
            return [['name' => 'BHCRM_Segments not loaded', 'pass' => false, 'message' => 'Skipped — required classes not found.']];
        }
        $rows = [];
        $rows = array_merge($rows, self::run_segment_condition_tests());
        $rows = array_merge($rows, self::run_csv_safe_tests());
        $rows = array_merge($rows, self::run_subtasks_tree_tests());
        if (class_exists('BHCRM_Projects')) {
            $rows = array_merge($rows, self::run_project_scene_tests());
        }
        if (class_exists('BHCRM_Projects') && class_exists('BH_Element')) {
            $rows = array_merge($rows, self::run_stall_analytics_tests());
        }
        if (class_exists('BHCRM_CardLog')) {
            $rows = array_merge($rows, self::run_card_log_tests());
        }
        if (class_exists('BHCRM_CardLog')) {
            $rows = array_merge($rows, self::run_idea_drop_tests());
        }
        return $rows;
    }

    /* ---------- Phase D: Idea Drop (track links + uploads) ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_idea_drop_tests(): array {
        $rows = [];
        global $wpdb;
        $card_id = 999002;

        // add_track_link() must reject anything that isn't a REAL
        // bhs_track post — a stray/garbage id, or a post of the wrong
        // type entirely, should never be linkable as if it were a
        // track.
        $rows[] = OUS_TestRunner::assert_false((bool) BHCRM_CardLog::add_track_link($card_id, 999999999, 1), 'add_track_link() rejects a nonexistent post id');
        $wrong_type_post = wp_insert_post(['post_type' => 'post', 'post_title' => 'Not a track', 'post_status' => 'draft'], true);
        $rows[] = OUS_TestRunner::assert_false((bool) BHCRM_CardLog::add_track_link($card_id, $wrong_type_post, 1), 'add_track_link() rejects a real post that is NOT a bhs_track');
        wp_delete_post($wrong_type_post, true);

        if (post_type_exists('bhs_track')) {
            $track_id = wp_insert_post(['post_type' => 'bhs_track', 'post_title' => 'Suite Test Track', 'post_status' => 'publish'], true);
            $link_id = BHCRM_CardLog::add_track_link($card_id, $track_id, 1);
            $rows[] = OUS_TestRunner::assert_true($link_id > 0, 'add_track_link() succeeds against a real bhs_track post');

            $attachments = BHCRM_CardLog::list_attachments($card_id);
            $rows[] = OUS_TestRunner::assert_same(1, count($attachments), 'list_attachments() returns exactly the one track link just added');
            $rows[] = OUS_TestRunner::assert_same('track_link', $attachments[0]['kind'], 'The stored row is kind=track_link');
            $rows[] = OUS_TestRunner::assert_same($track_id, (int) $attachments[0]['track_post_id'], 'track_post_id round-trips exactly');
            $rows[] = OUS_TestRunner::assert_same(0, (int) $attachments[0]['wp_attachment_id'], 'A track-link row never sets wp_attachment_id — the two are mutually exclusive');

            $removed = BHCRM_CardLog::remove_attachment($link_id);
            $rows[] = OUS_TestRunner::assert_true($removed, 'remove_attachment() succeeds');
            $rows[] = OUS_TestRunner::assert_same(0, count(BHCRM_CardLog::list_attachments($card_id)), 'The card has no attachments left after removal');

            wp_delete_post($track_id, true);
        } else {
            $rows[] = OUS_TestRunner::assert_true(true, 'bhs_track post type not registered in this environment — track-link round-trip skipped, rejection guards above still covered');
        }

        $rows[] = OUS_TestRunner::assert_false((bool) BHCRM_CardLog::add_upload($card_id, 0, 1), 'add_upload() rejects a zero/missing attachment id');

        // Cleanup
        $wpdb->delete(BHCRM_Tables::project_attachments(), ['card_placement_id' => $card_id]);

        return $rows;
    }

    /* ---------- Phase B: fixes + feedback log ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_card_log_tests(): array {
        $rows = [];
        global $wpdb;

        // No real card needed here — BHCRM_CardLog is keyed purely by
        // card_placement_id (an integer), never validating that the id
        // actually belongs to a real bh/sticky-card row (same posture
        // BHCRM_Projects' own card_moves table takes, cross-plugin,
        // no FK) — a fake fixture id exercises the exact same code path
        // a real card would.
        $card_id = 999001;

        $fix_id = BHCRM_CardLog::add_fix($card_id, 83, 'Fixed the muddy low end.');
        $rows[] = OUS_TestRunner::assert_true($fix_id > 0, 'add_fix() succeeds and returns a real row id');

        $fixes = BHCRM_CardLog::list_fixes($card_id);
        $rows[] = OUS_TestRunner::assert_same(1, count($fixes), 'list_fixes() returns exactly the one fix just added');
        $rows[] = OUS_TestRunner::assert_same(83, (int) $fixes[0]['timestamp_seconds'], 'The fix\'s timestamp_seconds round-trips exactly');
        $rows[] = OUS_TestRunner::assert_same(0, (int) $fixes[0]['resolved'], 'A new fix starts unresolved');

        $rows[] = OUS_TestRunner::assert_false((bool) BHCRM_CardLog::add_fix($card_id, 10, ''), 'add_fix() rejects an empty note rather than logging a blank row');

        $toggled = BHCRM_CardLog::toggle_fix_resolved($fix_id);
        $rows[] = OUS_TestRunner::assert_true($toggled, 'toggle_fix_resolved() succeeds');
        $fixes_after_toggle = BHCRM_CardLog::list_fixes($card_id);
        $this_fix = null;
        foreach ($fixes_after_toggle as $f) { if ((int) $f['id'] === $fix_id) $this_fix = $f; }
        $rows[] = OUS_TestRunner::assert_same(1, (int) ($this_fix['resolved'] ?? -1), 'toggle_fix_resolved() actually flips resolved to 1');

        BHCRM_CardLog::toggle_fix_resolved($fix_id);
        $fixes_after_second_toggle = BHCRM_CardLog::list_fixes($card_id);
        foreach ($fixes_after_second_toggle as $f) { if ((int) $f['id'] === $fix_id) $this_fix = $f; }
        $rows[] = OUS_TestRunner::assert_same(0, (int) $this_fix['resolved'], 'Toggling a second time flips it back to unresolved (a real toggle, not a one-way mark)');

        BHCRM_CardLog::add_feedback($card_id, 'A Client', 'Love the new mix, thank you!');
        $feedback = BHCRM_CardLog::list_feedback($card_id);
        $rows[] = OUS_TestRunner::assert_same(1, count($feedback), 'add_feedback()/list_feedback() round-trips exactly one entry');
        $rows[] = OUS_TestRunner::assert_same('A Client', $feedback[0]['author_name'], 'author_name is stored as free text, not resolved against any user account');

        BHCRM_CardLog::add_feedback($card_id, '', 'Feedback with no name given.');
        $feedback_after_blank_author = BHCRM_CardLog::list_feedback($card_id);
        $rows[] = OUS_TestRunner::assert_same('Anonymous', $feedback_after_blank_author[1]['author_name'] ?? null, 'An empty author_name falls back to "Anonymous" rather than storing a blank');

        $rows[] = OUS_TestRunner::assert_false((bool) BHCRM_CardLog::add_feedback($card_id, 'Someone', ''), 'add_feedback() rejects an empty note rather than logging a blank row');

        // Cleanup
        $wpdb->delete(BHCRM_Tables::project_fixes(), ['card_placement_id' => $card_id]);
        $wpdb->delete(BHCRM_Tables::project_feedback(), ['card_placement_id' => $card_id]);

        return $rows;
    }

    /* ---------- Phase C: stall analytics ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_stall_analytics_tests(): array {
        $rows = [];
        global $wpdb;

        $project_id = BHCRM_Projects::create('Stall Suite Test Project', 0, [], '');

        // A real bh/sticky-card placement, created through the SAME
        // save_placement() write path a live board uses — this is what
        // exercises the 'bhcore_element_placement_saved' hook for real,
        // not a mock of it.
        $card_id = BH_Element::save_placement([
            'surface' => 'bhcrm_project_board', 'surface_context_id' => $project_id, 'slot' => 'board',
            'position' => 0, 'element_type' => 'bh/sticky-card',
            'config' => ['attrs' => ['title' => 'Suite Test Card', 'column' => 'To Do']],
        ]);
        $rows[] = OUS_TestRunner::assert_true($card_id > 0, 'A real bh/sticky-card placement is created via save_placement()');

        $moves_table = BHCRM_Tables::project_card_moves();
        $initial_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $moves_table WHERE card_placement_id = %d", $card_id));
        $rows[] = OUS_TestRunner::assert_same(1, $initial_count, 'Creating a card logs exactly one initial move row (its starting column)');

        // An unrelated edit (title only, same column) must NOT log a
        // second move — this is the whole point of diffing old vs. new
        // config rather than logging on every save.
        BH_Element::save_placement([
            'id' => $card_id,
            'surface' => 'bhcrm_project_board', 'surface_context_id' => $project_id, 'slot' => 'board',
            'position' => 0, 'element_type' => 'bh/sticky-card',
            'config' => ['attrs' => ['title' => 'Suite Test Card (renamed)', 'column' => 'To Do']],
        ]);
        $count_after_rename = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $moves_table WHERE card_placement_id = %d", $card_id));
        $rows[] = OUS_TestRunner::assert_same(1, $count_after_rename, 'Saving the SAME column again (a title-only edit) does not log a second move');

        // A real column change DOES log a new move.
        BH_Element::save_placement([
            'id' => $card_id,
            'surface' => 'bhcrm_project_board', 'surface_context_id' => $project_id, 'slot' => 'board',
            'position' => 0, 'element_type' => 'bh/sticky-card',
            'config' => ['attrs' => ['title' => 'Suite Test Card (renamed)', 'column' => 'In Progress']],
        ]);
        $count_after_move = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $moves_table WHERE card_placement_id = %d", $card_id));
        $rows[] = OUS_TestRunner::assert_same(2, $count_after_move, 'A real column change logs a second move row');

        // average_hours_per_column() — checked HERE, before any
        // backdating below, while both move rows are still in their
        // real chronological order. The first card had a real,
        // measurable completed stay in "To Do" (its very first move) up
        // until it moved to "In Progress." That completed stay is the
        // ONLY thing average_hours_per_column() should count for "To
        // Do" — the CURRENT stay in "In Progress" hasn't ended yet and
        // must be excluded.
        $averages = BHCRM_Projects::average_hours_per_column($project_id);
        $rows[] = OUS_TestRunner::assert_true(isset($averages['To Do']), 'average_hours_per_column() reports a completed stay for "To Do"');
        $rows[] = OUS_TestRunner::assert_false(isset($averages['In Progress']), 'average_hours_per_column() excludes "In Progress" — that stay has not ended yet');

        // Backdate BOTH moves so the card reads as stalled — direct
        // SQL, not the public API, since "make time pass" isn't
        // something a real call can do. Deliberately done AFTER the
        // average_hours_per_column() check above (backdating before
        // that check would corrupt the chronological ordering it
        // depends on). BOTH rows need backdating, not just the most
        // recent one: a real test-design bug caught while writing this
        // suite (not a product bug) — backdating only the second
        // (most recent) row left the FIRST row's timestamp (still
        // "now," from its real insert moment) chronologically AFTER
        // the artificially-aged second row, so MAX(entered_at) picked
        // the first row and the card read as completely fresh instead
        // of stalled. Backdating both, in the correct relative order,
        // is what actually simulates "this card moved here N days ago
        // and has sat since."
        $wpdb->query($wpdb->prepare(
            "UPDATE $moves_table SET entered_at = %s WHERE card_placement_id = %d ORDER BY id ASC LIMIT 1",
            gmdate('Y-m-d H:i:s', time() - (BHCRM_Projects::STALL_DAYS + 10) * DAY_IN_SECONDS), $card_id
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE $moves_table SET entered_at = %s WHERE card_placement_id = %d ORDER BY id DESC LIMIT 1",
            gmdate('Y-m-d H:i:s', time() - (BHCRM_Projects::STALL_DAYS + 2) * DAY_IN_SECONDS), $card_id
        ));

        $stalled = BHCRM_Projects::stalled_cards_for_board($project_id);
        $rows[] = OUS_TestRunner::assert_true(isset($stalled[$card_id]) && $stalled[$card_id] >= BHCRM_Projects::STALL_DAYS, 'stalled_cards_for_board() flags a card whose most recent move is older than STALL_DAYS');

        // A second card whose only move is recent should NOT show up.
        $fresh_card_id = BH_Element::save_placement([
            'surface' => 'bhcrm_project_board', 'surface_context_id' => $project_id, 'slot' => 'board',
            'position' => 1, 'element_type' => 'bh/sticky-card',
            'config' => ['attrs' => ['title' => 'Fresh Card', 'column' => 'To Do']],
        ]);
        $stalled_after_fresh = BHCRM_Projects::stalled_cards_for_board($project_id);
        $rows[] = OUS_TestRunner::assert_false(isset($stalled_after_fresh[$fresh_card_id]), 'A card whose only move is recent is NOT flagged as stalled');

        // Cleanup
        $wpdb->delete($moves_table, ['card_placement_id' => $card_id]);
        $wpdb->delete($moves_table, ['card_placement_id' => $fresh_card_id]);
        BH_Element::delete_placement($card_id);
        BH_Element::delete_placement($fresh_card_id);
        BHCRM_Projects::delete($project_id);

        return $rows;
    }

    /* ---------- PROJECT-TRACKER-TRACKIT-PARITY-PLAN.md Phase E: scene ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_project_scene_tests(): array {
        $rows = [];

        $id_scened = BHCRM_Projects::create('Scene Suite Test Project A', 0, [], 'Album Launch');
        $id_unscened = BHCRM_Projects::create('Scene Suite Test Project B', 0, [], '');

        $rows[] = OUS_TestRunner::assert_true($id_scened > 0 && $id_unscened > 0, 'create() succeeds both with and without a scene');

        $scened = BHCRM_Projects::get($id_scened);
        $rows[] = OUS_TestRunner::assert_same('Album Launch', $scened['scene'] ?? null, 'A scene passed to create() is stored and read back exactly');

        $unscened = BHCRM_Projects::get($id_unscened);
        $rows[] = OUS_TestRunner::assert_same('', $unscened['scene'] ?? null, 'Omitting a scene at creation stores an empty string, not null/false');

        $updated = BHCRM_Projects::update_scene($id_unscened, 'Merch Drop');
        $rows[] = OUS_TestRunner::assert_true($updated, 'update_scene() succeeds against an existing project');
        $after_update = BHCRM_Projects::get($id_unscened);
        $rows[] = OUS_TestRunner::assert_same('Merch Drop', $after_update['scene'] ?? null, 'update_scene() actually persists the new value');

        $scenes = BHCRM_Projects::distinct_scenes();
        $rows[] = OUS_TestRunner::assert_true(in_array('Album Launch', $scenes, true) && in_array('Merch Drop', $scenes, true), 'distinct_scenes() includes every non-empty scene currently in use');
        $rows[] = OUS_TestRunner::assert_false(in_array('', $scenes, true), 'distinct_scenes() never includes the empty/unsorted scene as a suggestion');

        // Cleanup
        BHCRM_Projects::delete($id_scened);
        BHCRM_Projects::delete($id_unscened);

        return $rows;
    }

    /* ---------- BHCRM_Segments::sanitize_conditions() ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_segment_condition_tests(): array {
        $rows = [];

        $clean = BHCRM_Segments::sanitize_conditions([
            ['field' => 'tag', 'value' => 'vip'],
        ]);
        $rows[] = OUS_TestRunner::assert_same(
            [['field' => 'tag', 'value' => 'vip']], $clean,
            'sanitize_conditions(): a well-formed condition passes through unchanged'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [], BHCRM_Segments::sanitize_conditions([['field' => 'not_a_real_field', 'value' => 'x']]),
            'sanitize_conditions(): an unknown field is dropped entirely, never persisted as a filterable condition'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [], BHCRM_Segments::sanitize_conditions([['field' => 'tag', 'value' => '']]),
            'sanitize_conditions(): tag with an empty value is dropped (every field except has_project requires a real value)'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [['field' => 'has_project', 'value' => '']], BHCRM_Segments::sanitize_conditions([['field' => 'has_project', 'value' => '']]),
            'sanitize_conditions(): has_project with NO value is correctly kept — it is the one field that needs no value (a regression treating it like every other field would silently drop every has_project condition)'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [], BHCRM_Segments::sanitize_conditions([['value' => 'vip']]),
            'sanitize_conditions(): a condition missing "field" entirely is dropped, not treated as an empty-string field match'
        );

        $rows[] = OUS_TestRunner::assert_same(
            [], BHCRM_Segments::sanitize_conditions('not-an-array'),
            'sanitize_conditions(): a completely malformed (non-array) input degrades to an empty condition set rather than throwing'
        );

        return $rows;
    }

    /* ---------- BHCRM_Export::csv_safe() ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_csv_safe_tests(): array {
        if (!class_exists('BHCRM_Export')) return [];
        $rows = [];
        foreach (['=cmd', '+1', '-1', '@SUM(A1)'] as $formula) {
            $rows[] = OUS_TestRunner::assert_same(
                "'" . $formula, BHCRM_Export::csv_safe($formula),
                "csv_safe(): a value starting with '" . $formula[0] . "' gets a defusing leading apostrophe (CSV-injection guard)"
            );
        }
        $rows[] = OUS_TestRunner::assert_same(
            'Ordinary Name', BHCRM_Export::csv_safe('Ordinary Name'),
            'csv_safe(): an ordinary value is passed through unchanged'
        );
        $rows[] = OUS_TestRunner::assert_same(
            '', BHCRM_Export::csv_safe(''),
            'csv_safe(): an empty string does not throw on the $value[0] access and returns empty'
        );
        return $rows;
    }

    /* ---------- BHCRM_Subtasks tree helpers — private, via Reflection ---------- */

    /** @return array<int, array<string, mixed>> */
    private static function run_subtasks_tree_tests(): array {
        if (!class_exists('BHCRM_Subtasks')) return [];
        $rows = [];

        $find_node = new ReflectionMethod('BHCRM_Subtasks', 'find_node');
        $children_at = new ReflectionMethod('BHCRM_Subtasks', 'children_at');
        $total_count = new ReflectionMethod('BHCRM_Subtasks', 'total_node_count');

        $tree = [
            ['attrs' => ['uid' => 'a'], 'children' => [
                ['attrs' => ['uid' => 'a1'], 'children' => []],
                ['attrs' => ['uid' => 'a2'], 'children' => [
                    ['attrs' => ['uid' => 'a2i'], 'children' => []],
                ]],
            ]],
            ['attrs' => ['uid' => 'b'], 'children' => []],
        ];

        $found = &$find_node->invokeArgs(null, [&$tree, ['a', 'a2', 'a2i']]);
        $rows[] = OUS_TestRunner::assert_same(
            'a2i', $found['attrs']['uid'] ?? null,
            'find_node(): locates a node 3 levels deep by its exact uid path'
        );

        $missing = &$find_node->invokeArgs(null, [&$tree, ['a', 'nonexistent']]);
        $rows[] = OUS_TestRunner::assert_true(
            $missing === null,
            'find_node(): a path referencing a uid that does not exist (e.g. a deleted card) returns null rather than a wrong/partial node'
        );

        $children = &$children_at->invokeArgs(null, [&$tree, ['a']]);
        $rows[] = OUS_TestRunner::assert_same(
            2, count($children),
            'children_at(): returns the real children array (by reference) for an existing path'
        );

        $empty_children = &$children_at->invokeArgs(null, [&$tree, ['b', 'nonexistent']]);
        $rows[] = OUS_TestRunner::assert_same(
            0, count($empty_children),
            'children_at(): a path through a non-existent intermediate node returns an empty array, not a fatal error or a reference into the wrong node'
        );

        $rows[] = OUS_TestRunner::assert_same(
            5, $total_count->invoke(null, $tree),
            'total_node_count(): counts every node across every nesting level (a=1 + a1=1 + a2=1 + a2i=1 + b=1 = 5), catching an off-by-one or a level skipped during recursion'
        );

        $rows[] = OUS_TestRunner::assert_same(
            0, $total_count->invoke(null, []),
            'total_node_count(): an empty tree counts as 0, not an error'
        );

        $rows = array_merge($rows, self::run_subtasks_blank_uid_tests());

        return $rows;
    }

    /**
     * Regression coverage for a real data-loss bug found live: seeded/
     * imported sub-cards can end up with a blank 'uid' attr (real
     * UI-created cards always get one via wp_generate_password() in
     * add_subtask()). Two blank-uid siblings used to collide as the
     * SAME array key wherever BHCRM_Subtasks indexed children by uid,
     * silently deleting one of them on the very next drag-reorder save.
     * Covers both halves of the fix: backfill_uids() self-healing bad
     * data on read, and apply_reorder() never colliding/dropping a
     * blank-uid node even if one somehow still reaches it.
     */
    /** @return array<int, array<string, mixed>> */
    private static function run_subtasks_blank_uid_tests(): array {
        $rows = [];

        $backfill_uids = new ReflectionMethod('BHCRM_Subtasks', 'backfill_uids');
        $apply_reorder = new ReflectionMethod('BHCRM_Subtasks', 'apply_reorder');

        // --- backfill_uids(): self-heal on read ---
        $tree = [
            ['attrs' => ['uid' => '', 'title' => 'Thumbnail sketch'], 'children' => []],
            ['attrs' => ['uid' => '', 'title' => 'Lineart pass'], 'children' => []],
            ['attrs' => ['uid' => 'kept'], 'children' => [
                ['attrs' => ['uid' => ''], 'children' => []],
            ]],
        ];
        $changed = $backfill_uids->invokeArgs(null, [&$tree]);
        $rows[] = OUS_TestRunner::assert_true($changed, 'backfill_uids(): reports a change when it had to assign a missing uid');

        $uids = [$tree[0]['attrs']['uid'], $tree[1]['attrs']['uid'], $tree[2]['children'][0]['attrs']['uid']];
        $rows[] = OUS_TestRunner::assert_true(
            $uids[0] !== '' && $uids[1] !== '' && $uids[2] !== '',
            'backfill_uids(): every blank uid (top-level and nested) is replaced with a real, non-empty one'
        );
        $rows[] = OUS_TestRunner::assert_true(
            $uids[0] !== $uids[1] && $uids[0] !== $uids[2] && $uids[1] !== $uids[2],
            'backfill_uids(): two sibling blank-uid nodes are backfilled with DISTINCT uids, not the same one'
        );
        $rows[] = OUS_TestRunner::assert_same('kept', $tree[2]['attrs']['uid'], 'backfill_uids(): a node that already has a real uid is left untouched');

        $unchanged_tree = [['attrs' => ['uid' => 'x'], 'children' => []]];
        $rows[] = OUS_TestRunner::assert_false(
            $backfill_uids->invokeArgs(null, [&$unchanged_tree]),
            'backfill_uids(): reports no change when every uid was already present'
        );

        // --- apply_reorder(): two blank-uid siblings survive a reorder round-trip ---
        $columns = ['To Do', 'In Progress', 'Done'];
        $children = [
            ['attrs' => ['uid' => '', 'title' => 'Thumbnail sketch', 'column' => 'To Do', 'done' => false], 'children' => []],
            ['attrs' => ['uid' => '', 'title' => 'Lineart pass', 'column' => 'To Do', 'done' => false], 'children' => []],
            ['attrs' => ['uid' => 'real-uid', 'title' => 'Inking', 'column' => 'To Do', 'done' => false], 'children' => []],
        ];
        // Layout only mentions the one addressable (real-uid) card being
        // dragged to 'In Progress' — mirrors the client posting every
        // visible card, of which only real-uid ones can be named.
        $layout = [
            ['uid' => 'real-uid', 'column' => 'In Progress'],
        ];
        [$reordered, $state_changed] = $apply_reorder->invokeArgs(null, [$children, $layout, $columns]);

        $rows[] = OUS_TestRunner::assert_same(
            3, count($reordered),
            'apply_reorder(): both blank-uid siblings survive the reorder — neither is dropped by a uid-key collision'
        );
        $titles = array_map(fn($n) => $n['attrs']['title'], $reordered);
        $rows[] = OUS_TestRunner::assert_true(
            in_array('Thumbnail sketch', $titles, true) && in_array('Lineart pass', $titles, true),
            'apply_reorder(): both specific blank-uid cards ("Thumbnail sketch" and "Lineart pass") are present by name after the round-trip'
        );
        $rows[] = OUS_TestRunner::assert_false($state_changed, 'apply_reorder(): moving into a non-done column does not report a done-state flip');

        return $rows;
    }
}
