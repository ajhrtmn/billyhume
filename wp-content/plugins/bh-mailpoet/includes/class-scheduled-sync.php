<?php
if (!defined('ABSPATH')) exit;

/**
 * Daily full resync, catching anything the real-time hooks in
 * class-instant-sync.php miss or can't cover (bh-crm segment/profile
 * edits with no matching WP action, an admin adding someone to
 * bh_crm_active_user_ids some other way, etc.) — same self-rescheduling
 * OUS_Jobs pattern as bh-courses' BHC_DripNudges (class-drip-nudges.php).
 */
class BHMP_ScheduledSync {
    const JOB_HOOK = 'bhmp_daily_resync';

    public static function init(): void {
        if (!class_exists('OUS_Jobs')) return; // no queue infra, no job — same guard every other OUS_Jobs consumer in this ecosystem uses
        OUS_Jobs::register(self::JOB_HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'maybe_schedule_first_run']);
    }

    public static function maybe_schedule_first_run(): void {
        if (get_option('bhmp_resync_job_scheduled')) return;
        update_option('bhmp_resync_job_scheduled', time());
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::interval());
    }

    // Filterable so this can be tightened (or loosened) without a
    // redeploy — default daily, per the approved plan.
    private static function interval(): int {
        return (int) apply_filters('bhmp_sync_interval', DAY_IN_SECONDS);
    }

    /** @param array<string, mixed> $args */
    public static function run($args = []): void {
        // Reschedule first — a fatal partway through sync_all() shouldn't
        // silently kill the recurring job forever, same reasoning as
        // BHC_DripNudges::run()'s own comment.
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::interval());

        BHMP_Sync::sync_all();
    }
}
