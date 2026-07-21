<?php
if (!defined('ABSPATH')) exit;

/**
 * BHCRM_Debug — this plugin's own Debug Tools section, added 1.2.0
 * alongside the project tracker (class-projects.php). The shape here is copied
 * EXACTLY from bh-registry's BHR_Debug (bh-registry/includes/class-debug.php)
 * — same register()/render_section()/handle_action()/reset() contract,
 * same GROUP_SEED_RESET bucket, same seed-tag-in-the-name-so-Reset-
 * Everything-can-find-it convention. A standalone admin page was
 * deliberately NOT used for this seed button, for the same documented
 * reason element-builder.js's own docblock gives: this install has a
 * confirmed WordPress-core hook-resolution bug that broke standalone
 * pages outright — a Debug Tools SECTION is the one access pattern
 * proven to work here.
 */
class BHCRM_Debug {
    const SEED_TAG = '__bhcrm_seed__';

    public static function init() {
        add_filter('ous_debug_tools', [self::class, 'register']);
    }

    public static function register($tools) {
        $tools['bh-crm-projects'] = [
            'label'  => 'BH CRM — Project Tracker',
            'render' => [self::class, 'render_section'],
            'handle' => [self::class, 'handle_action'],
            'reset'  => [self::class, 'reset'],
            'group'  => OUS_Debug::GROUP_SEED_RESET,
        ];
        return $tools;
    }

    public static function render_section() {
        echo '<p>Seed one demo project (a fake commission) with a few sticky cards spread across its kanban columns, one of them carrying nested sub-task cards, so the project tracker board has something real to open and debug into immediately.</p>';
        echo OUS_Debug::button('bh-crm-projects', 'seed', 'Seed Project Tracker Demo Data');
    }

    public static function handle_action($action, $post) {
        if ($action !== 'seed') return '';
        if (!class_exists('BHCRM_Projects') || !class_exists('BH_Element')) {
            return 'BHCRM_Projects or BH_Element is unavailable — cannot seed.';
        }
        $result = self::seed();
        return $result
            ? "Seeded demo project #{$result['project_id']} with {$result['card_count']} cards (person #{$result['person_id']})."
            : 'Failed to seed demo project data.';
    }

    /**
     * Creates one demo project for a tagged test user (reusing
     * OUS_Debug::get_or_create_test_user(), the shared "tagged fake
     * account" helper every other seed action in this ecosystem already
     * uses — see class-debug.php's own docblock), then places four
     * bh/sticky-card placements across the project's default columns,
     * one of which ('In Progress') gets two nested 'bhcrm/sub-card'
     * children in its own BH_Content tree — a standard commission
     * checklist ("sketch, lineart") — so the roll-up label
     * (rollup_counts()) has something real to show and the nesting
     * bridge is exercised, not just the flat card list.
     */
    private static function seed() {
        $person_id = OUS_Debug::get_or_create_test_user('bhcrm_project');

        $project_name = 'Fenwick — Full Character Commission ' . self::SEED_TAG;
        $project_id = BHCRM_Projects::create($project_name, $person_id, BHCRM_Projects::DEFAULT_COLUMNS);
        if (!$project_id) return false;

        $cards = [
            ['title' => 'Confirm reference sheet',   'column' => 'To Do',       'done' => false],
            ['title' => 'Sketch pass',                'column' => 'In Progress', 'done' => false],
            ['title' => 'Line + color',                'column' => 'Review',      'done' => false],
            ['title' => 'Deliver final files',         'column' => 'Done',        'done' => true],
        ];

        $card_count = 0;
        $position = 0;
        foreach ($cards as $c) {
            $placement_id = BH_Element::save_placement([
                'surface'            => 'bhcrm_project_board',
                'surface_context_id' => $project_id,
                'slot'               => 'board',
                'position'           => $position++,
                'element_type'       => 'bh/sticky-card',
                'config'             => [
                    'attrs' => [
                        'title'  => ['literal' => $c['title'] . ' ' . self::SEED_TAG],
                        'notes'  => ['literal' => 'Seeded demo card for exercising the project tracker board.'],
                        'done'   => ['literal' => $c['done']],
                        'column' => ['literal' => $c['column']],
                    ],
                ],
            ]);
            if (!$placement_id) continue;
            $card_count++;

            // The "Sketch pass" card gets a standard commission-checklist
            // sub-tree, one level of nesting — the flagship "standard
            // commission checklist: sketch -> lineart -> color -> final
            // delivery" example the task brief names directly, saved as a
            // real BH_Content tree so it's also a genuine end-to-end proof
            // that the same shape is prefab-able (see BH_Element_Prefab::
            // save_from_slot()'s 'content_tree' snapshot handling).
            if ($c['column'] === 'In Progress' && class_exists('BH_Content')) {
                BH_Content::save('bh_element', (int) $placement_id, [
                    ['type' => 'bhcrm/sub-card', 'attrs' => ['title' => 'Thumbnail sketch', 'notes' => '', 'done' => true], 'children' => []],
                    ['type' => 'bhcrm/sub-card', 'attrs' => ['title' => 'Lineart pass', 'notes' => '', 'done' => false], 'children' => [
                        ['type' => 'bhcrm/sub-card', 'attrs' => ['title' => 'Clean up hands/face', 'notes' => '', 'done' => false], 'children' => []],
                    ]],
                ]);
            }
        }

        return ['project_id' => $project_id, 'person_id' => $person_id, 'card_count' => $card_count];
    }

    /**
     * Removes every project whose name carries SEED_TAG (and, via
     * BHCRM_Projects::delete()'s own cascade, every placement/content
     * document that belonged to it) — same "find by tag in the name,
     * regardless of which run created it" convention BHR_Debug::reset()
     * already establishes. The tagged test user itself is left alone,
     * matching OUS_Debug::get_or_create_test_user()'s own reuse-pool
     * design (other plugins' seed data may still reference the same
     * tagged account).
     */
    public static function reset() {
        global $wpdb;
        if (!class_exists('BHCRM_Projects')) return '0 seeded BH CRM project(s) removed.';

        $table = $wpdb->prefix . 'bhcrm_projects';
        $like = '%' . $wpdb->esc_like(self::SEED_TAG) . '%';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE name LIKE %s", $like));

        foreach ($ids as $id) {
            BHCRM_Projects::delete((int) $id);
        }
        return count($ids) . ' seeded BH CRM project(s) removed.';
    }
}
