<?php
/**
 * EVERY WORD PEOPLE SEE ON THE ACCOUNT PAGE (/account/): log in, create account,
 * confirming an email, and the Overview tab.
 *
 * Course, contest and support wording lives in each plugin's own words.php
 * (bh-courses/words.php, bh-contest/words.php, bh-tickets/words.php).
 *
 * To change wording, edit the text on the right of the arrow and save. Keep the
 * key on the left exactly as it is, and keep the quote marks around the text.
 * Write an apostrophe as \' (for example 'Couldn\'t').
 * Words in {curly braces} get filled in for you; keep them in your text.
 */
if (!defined('ABSPATH')) exit;

return [
    /* ---------- Log in ---------- */
    'login.sub'                 => 'Log in to your account',
    'login.sub_with_signup'     => 'Log in or create your account',
    'login.tab_login'           => 'Log in',
    'login.tab_signup'          => 'Create account',
    'login.username_or_email'   => 'Username or email',
    'login.password'            => 'Password',
    'login.code'                => '6-digit code',
    'login.remember'            => 'Keep me signed in',
    'login.submit'              => 'Log in',
    'login.forgot'              => 'Forgot your password?',
    'login.need_both'           => 'Enter your username or email and your password.',
    'login.failed'              => 'Invalid username or password.',
    'login.failed_generic'      => 'Login failed. Please try again.',
    'login.locked_out'          => 'Too many failed attempts. Please try again in 15 minutes.',
    'login.code_needed'         => 'Enter the 6-digit code from your authenticator app.',
    'login.code_wrong'          => 'That code didn\'t match. Try the newest code from your app.',

    /* ---------- Create account ---------- */
    'signup.username'           => 'Username',
    'signup.username_hint'      => 'Letters, numbers, spaces, and . - _ @',
    'signup.email'              => 'Email',
    'signup.password'           => 'Password',
    'signup.password_hint'      => 'At least 8 characters. A short phrase of a few words works well.',
    'signup.submit'             => 'Create account',
    'signup.welcome'            => 'Welcome! Your account is ready. We emailed you a link to confirm your address.',
    'signup.need_all'           => 'Fill in a username, email, and password.',
    'signup.need_username'      => 'Choose a username.',
    'signup.username_too_long'  => 'Usernames can be at most {max} characters.',
    'signup.username_bad_chars' => 'Usernames can use only letters (a–z), numbers, spaces, and . - _ @',
    'signup.username_taken'     => 'That username is already in use.',
    'signup.bad_email'          => 'Enter a valid email address.',
    'signup.email_taken'        => 'That email already has an account. Try logging in, or reset your password.',
    'signup.password_too_short' => 'Use a password of at least {min} characters.',
    'signup.password_too_long'  => 'Passwords can be at most {max} characters.',
    'signup.password_weak'      => 'That password is too easy to guess. Try a short phrase of a few words.',
    'signup.password_has_name'  => 'Your password shouldn\'t contain your username or email.',
    'signup.closed'             => 'New accounts aren\'t being accepted right now.',
    'signup.throttled'          => 'Too many sign-ups from this connection. Please try again later.',
    'signup.invalid'            => 'Please check the form and try again.',
    'signup.failed'             => 'Could not create your account. Please try again.',

    /* ---------- Confirm your email ---------- */
    'verify.title'              => 'Confirm your email.',
    'verify.text'               => 'We sent a link to {email}. Some features, like voting, need a confirmed email.',
    'verify.resend'             => 'Send the link again',
    'verify.sending'            => 'Sending…',
    'verify.sent'               => 'Sent. Check your inbox (and spam folder).',
    'verify.send_failed'        => 'Couldn\'t send the email. Please try again.',
    'verify.wait'               => 'Please wait a couple of minutes before requesting another email.',
    'verify.confirmed'          => 'Email confirmed — thanks!',
    'verify.link_bad'           => 'That confirmation link is invalid or has expired. You can request a new one from your account page.',

    /* ---------- Overview tab ---------- */
    'portal.overview_tab'       => 'Overview',
    'portal.welcome'            => 'Welcome back, {name}',
    'portal.membership'         => 'Membership',
    'portal.renews'             => 'renews {date}',
    'portal.renews_help'        => 'This is when your current access period ends. Whether it re-charges automatically depends on how you paid — check Membership & Wallet for the details.',
    'portal.continue_learning'  => 'Continue learning',
    'portal.done'               => 'done',
    'portal.continue'           => 'Continue →',
    'portal.contest_activity'   => 'Latest contest activity',
    'portal.votes_one'          => 'vote',
    'portal.votes'              => 'votes',
    'portal.view_submissions'   => 'View submissions →',
    'portal.notifications'      => 'Notifications',
    'portal.unread_one'         => 'unread notification',
    'portal.unread'             => 'unread notifications',
    'portal.view'               => 'View →',
    'portal.empty'              => 'Nothing to show yet — once you enroll in a course, submit to a contest, or pick up a supporter tier, it\'ll show up here.',
    'portal.browse_courses'     => 'Browse courses',
    'portal.see_contests'       => 'See contests',
    'portal.panel_empty'        => 'Nothing to show here yet.',

    /* ---------- Problems ---------- */
    'error.offline'             => 'Couldn\'t reach the site. Check your connection and try again.',
    'error.server'              => 'Something went wrong on our end (error {code}). Please try again.',
];
