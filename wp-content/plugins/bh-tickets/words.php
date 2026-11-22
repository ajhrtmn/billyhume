<?php
/**
 * EVERY WORD PEOPLE SEE ON THE SUPPORT FORM AND THE SUPPORT TAB OF THEIR ACCOUNT.
 *
 * To change wording, edit the text on the right of the arrow and save. Keep the
 * key on the left exactly as it is, and keep the quote marks around the text.
 * Write an apostrophe as \' (for example 'That didn\'t send').
 */
if (!defined('ABSPATH')) exit;

return [
    /* ---------- Support page contact form ---------- */
    'support.heading'       => 'Contact us',
    'support.name'          => 'Your name',
    'support.email'         => 'Your email',
    'support.subject'       => 'Subject',
    'support.message'       => 'Message',
    'support.send'          => 'Send message',
    'support.past_messages' => 'See your past messages →',
    'support.sent_member'   => 'Thanks, we got your message. Replies show up under Support in your account.',
    'support.sent_guest'    => 'Thanks, we got your message and will reply by email.',
    'support.invalid'       => 'Please fill in every field with a real email address.',
    'support.limit'         => 'Too many messages from this connection. Please try again in an hour.',
    'support.error'         => 'That didn\'t send. Please try again.',

    /* ---------- "Support" tab in the account area ---------- */
    'portal.tab'            => 'Support',
    'portal.new_heading'    => 'New ticket',
    'portal.subject'        => 'Subject',
    'portal.body'           => 'How can we help?',
    'portal.submit'         => 'Submit',
    'portal.submitted'      => 'Ticket submitted.',
    'portal.list_heading'   => 'Your tickets',
    'portal.none'           => 'No tickets yet.',
    'portal.col_subject'    => 'Subject',
    'portal.col_status'     => 'Status',
    'portal.col_updated'    => 'Updated',
    'portal.back'           => '← Your tickets',
    'portal.status_label'   => 'Status:',
    'portal.staff_name'     => 'Support',
    'portal.you'            => 'You',
    'portal.reply'          => 'Reply',
    'portal.reply_sent'     => 'Reply sent.',

    /* Ticket statuses, as people see them */
    'status.open'           => 'Open',
    'status.pending'        => 'Pending',
    'status.resolved'       => 'Resolved',
    'status.closed'         => 'Closed',
    /* =================================================================
     * ADMIN SCREENS (wp-admin). Some text keeps small bits of HTML from the
     * original, like <code> or &rarr; (an arrow); leave those as they are.
     * ================================================================= */

    /* ---------- admin (includes/class-admin.php) ---------- */
    'admin.admin.support_tickets'    => 'Support Tickets',
    'admin.admin.all'                => 'All',
    'admin.admin.no_tickets'         => 'No tickets.',
    'admin.admin.subject'            => 'Subject',
    'admin.admin.requester'          => 'Requester',
    'admin.admin.priority'           => 'Priority',
    'admin.admin.status'             => 'Status',
    'admin.admin.assigned'           => 'Assigned',
    'admin.admin.updated'            => 'Updated',
    'admin.admin.ticket_not_found'   => 'Ticket not found.',
    'admin.admin.requester_2'        => 'Requester:',
    'admin.admin.update_status'      => 'Update status',
    'admin.admin.assign'             => 'Assign',
    'admin.admin.staff'              => 'Staff',
    'admin.admin.reply'              => 'Reply',
    'admin.admin.send_reply'         => 'Send reply',
    'admin.admin.reply_sent'         => 'Reply sent.',
    'admin.admin.status_updated'     => 'Status updated.',
    'admin.admin.assignment_updated' => 'Assignment updated.',

    /* ---------- replies (includes/class-replies.php) ---------- */
    'admin.replies.reply_needs_body'            => 'A reply needs a body.',
    'admin.replies.could_not_save_reply'        => 'Could not save the reply.',
    'admin.replies.new_reply_ticket'            => 'New reply on your ticket:',
    'admin.replies.someone_replied_take_look'   => 'Someone replied — take a look.',
    'admin.replies.bh_tickets'                  => 'BH Tickets',
    'admin.replies.new_reply_assigned_ticket'   => 'New reply on assigned ticket:',
    'admin.replies.requester_replied_take_look' => 'The requester replied — take a look.',

    /* ---------- support (includes/class-support.php) ---------- */
    'admin.support.website'                     => 'Website',
    'admin.support.request_must_come_from_site' => 'This request must come from this site.',
    'admin.support.support_message'             => 'Support message:',
    'admin.support.wrote_no_account_reply_by'   => 'wrote (no account, reply by email):',

    /* ---------- tickets (includes/class-tickets.php) ---------- */
    'admin.tickets.ticket_needs_real_account_subject' => 'A ticket needs a real account and a subject.',
    'admin.tickets.could_not_create_ticket'           => 'Could not create the ticket.',
    'admin.tickets.new_support_ticket'                => 'New support ticket:',
    'admin.tickets.new_ticket_needs_triage'           => 'A new ticket needs triage.',
];
