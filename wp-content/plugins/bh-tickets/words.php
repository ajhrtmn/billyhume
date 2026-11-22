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
];
