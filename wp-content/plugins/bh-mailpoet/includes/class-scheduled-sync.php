<?php
if (!defined('ABSPATH')) exit;

/** Daily self-rescheduling OUS_Jobs full resync, catching anything the real-time hooks miss. */
class BHMP_ScheduledSync {
    const JOB_HOOK = 'bhmp_daily_resync';

    public static function init(): void {
        if (!class_exists('OUS_Jobs')) return;
        OUS_Jobs::register(self::JOB_HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'maybe_schedule_first_run']);
    }

    public static function maybe_schedule_first_run(): void {
        if (get_option('bhmp_resync_job_scheduled')) return;
        update_option('bhmp_resync_job_scheduled', time());
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::interval());
    }

    // Filterable interval, default daily.
    private static function interval(): int {
        return (int) apply_filters('bhmp_sync_interval', DAY_IN_SECONDS);
    }

    /** @param array<string, mixed> $args */
    public static function run($args = []): void {
        // WHY: reschedule first so a fatal mid-sync can't kill the recurring job.
        OUS_Jobs::enqueue(self::JOB_HOOK, [], self::interval());

        BHMP_Sync::sync_all();
    }
}
