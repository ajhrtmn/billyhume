<?php
if (!defined('ABSPATH')) exit;

/** OUS_TestRunner suite, registered via bhcore_test_suites — same convention as OUS_Campaigns::run_tests()/bh-crm's own suite. */
class BHT_TestSuite {
    public static function init(): void {
        add_filter('bhcore_test_suites', [self::class, 'register']);
    }

    /**
     * @param array<string, mixed> $suites
     * @return array<string, mixed>
     */
    public static function register(array $suites): array {
        $suites['bh-tickets'] = ['label' => 'BH Tickets', 'callback' => [self::class, 'run_tests']];
        return $suites;
    }

    /** @return array<int, array<string, mixed>> */
    public static function run_tests(): array {
        if (!class_exists('OUS_Debug') || !class_exists('OUS_TestRunner')) {
            return [['name' => 'OUS_Debug/OUS_TestRunner not loaded', 'pass' => false, 'message' => 'Skipped.']];
        }
        global $wpdb;
        $rows = [];

        $requester = OUS_Debug::get_or_create_test_user('bht_suite_requester', false);
        $staff = OUS_Debug::get_or_create_test_user('bht_suite_staff', false);

        $ticket_id = BHT_Tickets::create($requester, 'Suite: cannot access course', 'Opening message.', 'account', 'high');
        $rows[] = OUS_TestRunner::assert_true(is_int($ticket_id) && $ticket_id > 0, 'create() returns a new ticket id');

        $ticket = BHT_Tickets::get($ticket_id);
        $rows[] = OUS_TestRunner::assert_same('open', $ticket['status'] ?? null, 'A new ticket starts as open');
        $rows[] = OUS_TestRunner::assert_same('high', $ticket['priority'] ?? null, 'Priority is stored as given');

        $replies = BHT_Replies::for_ticket($ticket_id);
        $rows[] = OUS_TestRunner::assert_same(1, count($replies), 'The opening message is stored as the first reply');
        $rows[] = OUS_TestRunner::assert_same(0, (int) ($replies[0]['is_staff'] ?? 1), 'The opening message is not a staff reply');

        $reply_id = BHT_Replies::add($ticket_id, $staff, 'We are looking into it.', true);
        $rows[] = OUS_TestRunner::assert_true(is_int($reply_id) && $reply_id > 0, 'A staff reply is saved');

        $after_reply = BHT_Tickets::get($ticket_id);
        $rows[] = OUS_TestRunner::assert_true($after_reply['updated_at'] >= $ticket['updated_at'], 'A new reply bumps updated_at');

        $set = BHT_Tickets::set_status($ticket_id, 'resolved');
        $rows[] = OUS_TestRunner::assert_true($set, 'set_status() to a valid status succeeds');
        $rows[] = OUS_TestRunner::assert_same('resolved', BHT_Tickets::get($ticket_id)['status'], 'Status actually persisted');

        $bad_status = BHT_Tickets::set_status($ticket_id, 'not_a_real_status');
        $rows[] = OUS_TestRunner::assert_true($bad_status === false, 'set_status() rejects an unknown status');

        $assigned = BHT_Tickets::assign($ticket_id, $staff);
        $rows[] = OUS_TestRunner::assert_true($assigned, 'assign() succeeds');
        $rows[] = OUS_TestRunner::assert_same($staff, (int) BHT_Tickets::get($ticket_id)['assigned_to'], 'Assignment persisted');

        $for_user = BHT_Tickets::for_user($requester);
        $rows[] = OUS_TestRunner::assert_true(in_array($ticket_id, array_column($for_user, 'id'), true), 'for_user() includes the requester\'s own ticket');

        if (class_exists('BHCRM_Links')) {
            $links = BHCRM_Links::for_from('ticket', $ticket_id);
            $rows[] = OUS_TestRunner::assert_true(count($links) > 0, 'A ticket links to its requester via BHCRM_Links when bh-crm is active');
        }

        // Cleanup.
        $wpdb->delete($wpdb->prefix . 'bhtickets_replies', ['ticket_id' => $ticket_id]);
        $wpdb->delete($wpdb->prefix . 'bhtickets_tickets', ['id' => $ticket_id]);

        return $rows;
    }
}
