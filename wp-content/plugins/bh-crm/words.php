<?php
/**
 * EVERY WORD ON THE PEOPLE AND PROJECT TRACKER SCREENS.
 *
 * To change wording, edit the text on the right of the arrow and save. Keep the
 * key on the left exactly as it is, and keep the quote marks around the text.
 * Write an apostrophe as \' (for example 'Don\'t').
 */
if (!defined('ABSPATH')) exit;

return [
    /* =================================================================
     * ADMIN SCREENS (wp-admin). Some text keeps small bits of HTML from the
     * original, like <code> or &rarr; (an arrow); leave those as they are.
     * ================================================================= */

    /* ---------- card log (includes/class-card-log.php) ---------- */
    'admin.card_log.no_file_chosen'               => 'No file chosen.',
    'admin.card_log.file_must_smaller_than_100mb' => 'File must be smaller than 100MB.',
    'admin.card_log.idea_drop'                    => 'Idea Drop',
    'admin.card_log.no_files_linked_uploaded_yet' => 'No files linked or uploaded yet.',
    'admin.card_log.track'                        => 'Track #',
    'admin.card_log.linked_track'                 => '(linked track)',
    'admin.card_log.attachment'                   => 'Attachment #',
    'admin.card_log.uploaded_copy'                => '(uploaded copy)',
    'admin.card_log.remove'                       => 'Remove',
    'admin.card_log.upload_file'                  => 'Upload a file:',
    'admin.card_log.upload'                       => 'Upload',
    'admin.card_log.fixes'                        => 'Fixes',
    'admin.card_log.timestamp_mm_ss_optional'     => 'Timestamp (mm:ss, optional)',
    'admin.card_log.add_fix'                      => 'Add fix',
    'admin.card_log.no_fixes_logged_yet'          => 'No fixes logged yet.',
    'admin.card_log.mark_resolved'                => 'Mark resolved',
    'admin.card_log.feedback'                     => 'Feedback',
    'admin.card_log.add_feedback'                 => 'Add feedback',
    'admin.card_log.no_feedback_logged_yet'       => 'No feedback logged yet.',

    /* ---------- event activity (includes/class-event-activity.php) ---------- */
    'admin.event_activity.played_track'                    => 'Played a track',
    'admin.event_activity.skipped_track'                   => 'Skipped a track',
    'admin.event_activity.cast_contest_vote'               => 'Cast a contest vote',
    'admin.event_activity.enrolled_course'                 => 'Enrolled in a course',
    'admin.event_activity.completed_lesson_step'           => 'Completed a lesson step',
    'admin.event_activity.completed_course'                => 'Completed a course',
    'admin.event_activity.crm_note_updated'                => 'CRM note updated',
    'admin.event_activity.crm_tags_updated'                => 'CRM tags updated',
    'admin.event_activity.linked_project'                  => 'Linked to a project',
    'admin.event_activity.submitted_contest_entry'         => 'Submitted a contest entry',
    'admin.event_activity.wallet_credited'                 => 'Wallet credited',
    'admin.event_activity.wallet_debited_play'             => 'Wallet debited (play)',
    'admin.event_activity.received_email'                  => 'Received an email',
    'admin.event_activity.event_tracking'                  => 'Event Tracking',
    'admin.event_activity.d_recorded_event_s'              => '%d recorded event%s',
    'admin.event_activity.no_events_recorded'              => 'No events recorded.',
    'admin.event_activity.wallet_events_below_are_also'    => 'Wallet events below are also summarized, rolled up, in this contact\'s wallet-balance section above.',
    'admin.event_activity.when'                            => 'When',
    'admin.event_activity.event'                           => 'Event',
    'admin.event_activity.subject'                         => 'Subject',
    'admin.event_activity.details'                         => 'Details',
    'admin.event_activity.most_recent_events_older_events' => 'most recent events. Older events aren\'t shown here — see the Event Tracking Debug Tools section (The Self-Hosted Self) for aggregate counts.',

    /* ---------- export (includes/class-export.php) ---------- */
    'admin.export.real_name' => 'Real name',

    /* ---------- hub (includes/class-hub.php) ---------- */
    'admin.hub.bh_crm_hub_top_level'       => 'bh-crm-hub (top-level)',
    'admin.hub.bh_crm_hub_relabeled_first' => 'bh-crm-hub (relabeled first submenu)',

    /* ---------- notes (includes/class-notes.php) ---------- */
    'admin.notes.migrated_from_old_single_note'   => '(Migrated from the old single-note field)',
    'admin.notes.notes'                           => 'Notes',
    'admin.notes.remind_me'                       => 'Remind me',
    'admin.notes.optional_leave_blank_plain_note' => 'Optional — leave blank for a plain note with no follow-up.',
    'admin.notes.no_notes_yet'                    => 'No notes yet.',
    'admin.notes.reminder_done'                   => 'Reminder (done):',
    'admin.notes.reminder_due'                    => 'Reminder due:',
    'admin.notes.reminder_set'                    => 'Reminder set for:',
    'admin.notes.could_not_save_note_please'      => 'Could not save note — please try again.',
    'admin.notes.could_not_save_note'             => 'Could not save note.',

    /* ---------- people (includes/class-people.php) ---------- */
    'admin.people.no_one_has_profile_file'          => 'No one has a profile on file or any recorded activity yet. This list fills in on its own — nothing to configure.',
    'admin.people.anyone_with_profile_data_file'    => 'Anyone with profile data on file or recorded activity. Click a name for their full detail.',
    'admin.people.filter_by_tag'                    => 'Filter by tag:',
    'admin.people.all'                              => 'All',
    'admin.people.export_csv_all'                   => 'Export CSV (all)',
    'admin.people.export_selected_csv'              => 'Export selected (CSV)',
    'admin.people.name'                             => 'Name',
    'admin.people.email'                            => 'Email',
    'admin.people.tags'                             => 'Tags',
    'admin.people.activity'                         => 'Activity',
    'admin.people.registered'                       => 'Registered',
    'admin.people.no_saved_lists_yet_build'         => 'No saved lists yet — build one below (e.g. "tagged vip AND registered after a date AND has an active project").',
    'admin.people.build_new_list'                   => '+ Build a new list',
    'admin.people.add_condition'                    => '+ Add condition',
    'admin.people.profile'                          => 'Profile',
    'admin.people.no_profile_data_collected_yet'    => 'No profile data collected yet.',
    'admin.people.field'                            => 'Field',
    'admin.people.value'                            => 'Value',
    'admin.people.consent_share'                    => 'Consent to share',
    'admin.people.keep_private'                     => 'Keep private',
    'admin.people.usually_watches'                  => 'Usually watches on:',
    'admin.people.phone'                            => 'Phone',
    'admin.people.direct_contact_only_never_shared' => '(direct contact only, never shared):',
    'admin.people.phone_file_hidden_admin_only'     => 'Phone on file — hidden (admin only).',
    'admin.people.profile_page_not_public'          => 'Profile page not public',
    'admin.people.user_not_found'                   => 'User not found.',
    'admin.people.edit_wordpress_profile'           => 'Edit WordPress profile',
    'admin.people.bh_crm'                           => 'BH CRM',
    'admin.people.people_tracked'                   => 'People tracked',
    'admin.people.total_all_time'                   => 'Total, all-time',
    'admin.people.relationship_links'               => 'Relationship links',
    'admin.people.last_12_months'                   => 'Last 12 months',

    /* ---------- projects (includes/class-projects.php) ---------- */
    'admin.projects.every_project_board_across_crm'           => 'Every project board across the CRM. Opening one lands on the same kanban board view reachable from a person\'s own profile — this is just a cross-person index, not a second board implementation.',
    'admin.projects.create_project'                           => 'Create project',
    'admin.projects.no_projects_yet_create_one'               => 'No projects yet — create one above, then link people to it from the board.',
    'admin.projects.project'                                  => 'Project',
    'admin.projects.people'                                   => 'People',
    'admin.projects.cards'                                    => 'Cards',
    'admin.projects.updated'                                  => 'Updated',
    'admin.projects.user'                                     => 'User #',
    'admin.projects.no_one_linked'                            => 'No one linked',
    'admin.projects.open_board'                               => 'Open board',
    'admin.projects.untitled_project'                         => 'Untitled project',
    'admin.projects.project_tracker_board'                    => 'Project tracker board',
    'admin.projects.board'                                    => 'Board',
    'admin.projects.sticky_card'                              => 'Sticky card',
    'admin.projects.untitled_task'                            => 'Untitled task',
    'admin.projects.projects'                                 => 'Projects',
    'admin.projects.columns'                                  => 'Columns',
    'admin.projects.delete'                                   => 'Delete',
    'admin.projects.no_projects_yet_person'                   => 'No projects yet for this person.',
    'admin.projects.failed_create_project'                    => 'Failed to create project.',
    'admin.projects.columns_updated'                          => 'Columns updated.',
    'admin.projects.scene_updated'                            => 'Scene updated.',
    'admin.projects.project_deleted'                          => 'Project deleted.',
    'admin.projects.project_not_found'                        => 'Project not found.',
    'admin.projects.edit_columns'                             => 'Edit columns',
    'admin.projects.one_column_label_per_line'                => 'One column label per line, in the order they should appear on the board.',
    'admin.projects.edit_scene'                               => 'Edit scene',
    'admin.projects.purely_organizational_groups_project_und' => 'Purely organizational — groups this project under a heading on the Project Tracker index. Leave blank for "Unsorted".',
    'admin.projects.kanban_board_requires_javascript'         => 'The kanban board requires JavaScript.',
    'admin.projects.no_one_linked_project_yet'                => 'No one linked to this project yet.',
    'admin.projects.link_person'                              => 'Link person',

    /* ---------- segments (includes/class-segments.php) ---------- */
    'admin.segments.name_list_before_saving' => 'Name your list before saving.',
    'admin.segments.add_at_least_one_real'   => 'Add at least one real condition before saving.',
    'admin.segments.crm_everyone_active_crm' => 'CRM: everyone active in the CRM',
    'admin.segments.crm_list'                => 'CRM list:',
    'admin.segments.list_deleted'            => 'List deleted.',

    /* ---------- subtasks (includes/class-subtasks.php) ---------- */
    'admin.subtasks.card_not_found'               => 'Card not found.',
    'admin.subtasks.sub_tasks_across_every_level' => 'sub-tasks across every level — worth considering whether this has grown into its own separate project instead of one card\'s tree.',
    'admin.subtasks.open_board'                   => 'Open board (',
    'admin.subtasks.add'                          => 'Add',
    'admin.subtasks.add_several_at_once'          => '+ Add several at once',
    'admin.subtasks.one_sub_task_title_per'       => 'One sub-task title per line, all added to:',
    'admin.subtasks.no_layout_given'              => 'No layout given.',
    'admin.subtasks.sub_task_not_found'           => 'Sub-task not found.',

    /* ---------- tags (includes/class-tags.php) ---------- */
    'admin.tags.enter_tag_apply' => 'Enter a tag to apply.',
];
