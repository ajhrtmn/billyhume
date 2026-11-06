<?php
if (!defined('ABSPATH')) exit;

/**
 * Debug Tools section with project-tracker seed/reset, same contract as BHR_Debug.
 * WHY: a Debug Tools section, not a standalone admin page; standalone pages have failed hook resolution on this install.
 */
class BHCRM_Debug {
    const SEED_TAG = '__bhcrm_seed__';

    public static function init(): void {
        add_filter('ous_debug_tools', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    public static function register($tools): array {
        $tools['bh-crm-projects'] = [
            'label'  => 'BH CRM — Project Tracker',
            'render' => [self::class, 'render_section'],
            'handle' => [self::class, 'handle_action'],
            'reset'  => [self::class, 'reset'],
            'group'  => OUS_Debug::GROUP_SEED_RESET,
        ];
        return $tools;
    }

    public static function render_section(): void {
        echo '<p>Seed one demo project (a fake commission) with a few sticky cards spread across its kanban columns, one of them carrying nested sub-task cards, so the project tracker board has something real to open and debug into immediately.</p>';
        OUS_Debug::button('bh-crm-projects', 'seed', 'Seed Project Tracker Demo Data');
    }

    /**
     * @param mixed $post
     */
    public static function handle_action(string $action, $post): string {
        if ($action !== 'seed') return '';
        if (!class_exists('BHCRM_Projects') || !class_exists('BH_Element')) {
            return 'BHCRM_Projects or BH_Element is unavailable — cannot seed.';
        }
        $result = self::seed();
        return $result
            ? "Seeded demo project #{$result['project_id']} with {$result['card_count']} cards (person #{$result['person_id']})."
            : 'Failed to seed demo project data.';
    }

    /** Seeds a demo project for the tagged test user: four cards, one with nested sub-cards to exercise roll-up. */
    /** @return array<string, int>|false */
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

            // One level of nested sub-cards.
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

    /** Deletes every SEED_TAG project (cascading placements/content); the shared test user is kept. */
    public static function reset(): string {
        global $wpdb;
        if (!class_exists('BHCRM_Projects')) return '0 seeded BH CRM project(s) removed.';

        $table = BHCRM_Tables::projects();
        $like = '%' . $wpdb->esc_like(self::SEED_TAG) . '%';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE name LIKE %s", $like));

        foreach ($ids as $id) {
            BHCRM_Projects::delete((int) $id);
        }
        return count($ids) . ' seeded BH CRM project(s) removed.';
    }
}
