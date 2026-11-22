<?php
/**
 * EVERY WORD VOTERS AND ENTRANTS SEE IN CONTESTS: button labels, headings and messages.
 *
 * To change wording, edit the text on the right of the arrow and save. Keep the
 * key on the left exactly as it is, and keep the quote marks around the text.
 * Write an apostrophe as \' (for example 'You\'re in!').
 *
 * Words in {curly braces} get filled in for you, like {count} or {date}.
 * Keep them in your text (you can move them around), and don't translate them.
 *
 * Some messages come in pairs, one for "1" and one for more ("1 vote" / "3 votes").
 */
if (!defined('ABSPATH')) exit;

return [
    /* ---------- Contest player: top bar ---------- */
    'results.button'            => 'Results',
    'submit.button'             => 'Submit a Song',
    'auth.login_button'         => 'Log In',
    'auth.logout_button'        => 'Log Out',
    'player.select_track'       => 'Select a track',
    'player.no_audio'           => 'This track has no audio file.',

    /* ---------- Track list and voting ---------- */
    'tracks.loading'            => 'Loading tracks…',
    'tracks.none'               => 'No tracks yet. Be the first to submit!',
    'tracks.load_failed'        => 'Could not load tracks.',
    'vote.button'               => 'Vote',
    'vote.voted'                => 'Voted',
    'vote.closed'               => 'Closed',
    'vote.closed_banner'        => 'Voting has closed for this contest.',
    'vote.login_required'       => 'Log in to vote.',
    'vote.left_one'             => '{count} vote left',
    'vote.left'                 => '{count} votes left',
    'vote.left_of_one'          => '{count} vote left of {limit}',
    'vote.left_of'              => '{count} votes left of {limit}',
    'vote.none_left'            => 'No votes left in this category',
    // {category} below is filled with the next line (" for Best Mix"), or nothing when
    // the contest has no categories. Keep the space at the start of this one.
    'vote.for_category'         => ' for {category}',
    'vote.counted_one_left'     => 'Vote counted{category} — {count} vote left.',
    'vote.counted'              => 'Vote counted{category} — {count} votes left.',
    'vote.counted_last'         => 'Vote counted{category} — that was your last vote here.',
    'vote.removed'              => 'Vote removed{category} — you can pick another track.',
    'vote.failed'               => 'Could not record your vote.',

    /* ---------- Log in / sign up box ---------- */
    'auth.login_title'          => 'Log In',
    'auth.signup_title'         => 'Sign Up',
    'auth.username'             => 'Username',
    'auth.password'             => 'Password',
    'auth.show_password'        => 'Show password',
    'auth.hide_password'        => 'Hide password',
    'auth.email'                => 'Email (sign up only)',
    'auth.signup_extra_hint'    => 'Optional — helps us credit you if you ever submit a track. Skip anything you\'d rather not share.',
    'auth.continue'             => 'Continue',
    'auth.switch_to_signup'     => 'Need an account? Sign up',
    'auth.switch_to_login'      => 'Have an account? Log in',
    'auth.logging_in'           => 'Logging in…',
    'auth.creating_account'     => 'Creating account…',
    'auth.welcome_back'         => 'Welcome back!',
    'auth.account_created'      => 'Account created — you\'re now signed in.',
    'auth.failed'               => 'Authentication failed.',
    'auth.logged_out'           => 'Logged out.',
    'auth.session_expired'      => 'Your session has expired — please log in again.',

    /* Profile fields (sign up box and submit box) */
    'profile.real_name'         => 'Real name',
    'profile.discord'           => 'Discord username',
    'profile.twitch'            => 'Twitch username',
    'profile.youtube'           => 'YouTube channel',
    'profile.public'            => 'public',
    'profile.platform_question' => 'Where do you usually watch?',

    /* ---------- Submit a song box ---------- */
    'submit.title'              => 'Submit Your Track',
    'submit.song_title'         => 'Song title',
    'submit.artist'             => 'Artist name',
    'submit.contact_hint'       => 'We need your real name and at least one way to reach you, so we can credit you properly.',
    'submit.phone'              => 'Phone number (optional — for prize contact only, never shared)',
    'submit.note'               => 'Note to admins (optional)',
    'submit.choose_file'        => 'Choose an audio file…',
    'submit.file_hint'          => 'MP3 or M4A · Max 20MB',
    'submit.upload'             => 'Upload',
    'submit.uploading'          => 'Uploading… please wait',
    'submit.saving'             => 'Saving…',
    'submit.need_title'         => 'Add a song title and artist name.',
    'submit.need_title_and_file' => 'Add a song title, artist name, and an audio file.',
    'submit.file_too_big'       => 'That file is over 20MB. Please choose a smaller one.',
    'submit.started'            => 'Submission started! Attach your audio file from your account portal to finish.',
    'submit.done'               => 'Track submitted! It will appear once an admin approves it.',
    'submit.failed'             => 'Upload failed. Check the file and try again.',
    // {items} is a list built from the three lines after it, joined by submit.list_and.
    'submit.need_these'         => 'We need {items} before you can submit.',
    'submit.need_real_name'     => 'your real name',
    'submit.need_handle'        => 'at least one way to reach you (Discord, Twitch, or YouTube)',
    'submit.need_phone'         => 'a phone number',
    'submit.list_and'           => ' and ',
    'submit.all_optional'       => 'Optional — fill in whatever you\'d like below.',

    /* After submitting: share box */
    'share.title'               => 'You\'re in! 🎉',
    'share.intro'               => 'Grab a shareable image to spread the word.',
    'share.next_with_date'      => 'Voting closes {date} — winners are announced live at the Reveal Party.',
    'share.next'                => 'Watch for the Reveal Party once voting closes — that\'s when winners are announced.',
    'share.entered_button'      => 'Get "I entered" image',
    'share.entered_alt'         => 'Now entered — shareable image',
    'share.vote_button'         => 'Get "Vote for me" image',
    'share.vote_alt'            => 'Vote for me — shareable image',
    'share.link_hint'           => 'Pair the "Vote for me" image with this link when you post:',
    'share.copy'                => 'Copy',
    'share.copy_link'           => 'Copy link',
    'share.copied'              => 'Copied!',

    /* ---------- Results box ---------- */
    'results.title'             => 'Results',
    'results.loading'           => 'Loading…',
    'results.not_ready'         => 'Results are not available yet.',
    'results.no_votes'          => 'No votes have been cast yet.',
    'results.judges_pick'       => 'Judges\' Pick',
    'results.peoples_choice'    => 'People\'s Choice',

    /* ---------- Reveal Party page ---------- */
    'reveal.waiting'            => 'Waiting for reveal to start…',
    'reveal.none'               => 'No contest ready to reveal yet.',
    'reveal.results'            => 'Results',
    'reveal.now_revealing'      => 'Now Revealing',
    'reveal.category'           => 'Category',
    'reveal.entries_one'        => '{count} entry',
    'reveal.entries'            => '{count} entries',
    'reveal.grand_finale'       => 'Grand Finale',
    'reveal.overall'            => 'Overall',
    'reveal.overall_entries_one' => 'Across all categories — {count} entry',
    'reveal.overall_entries'    => 'Across all categories — {count} entries',
    'reveal.thanks'             => 'Thanks for watching!',

    /* ---------- Contests page (cards) ---------- */
    'library.group_open'        => 'Open now',
    'library.group_upcoming'    => 'Starting soon',
    'library.group_past'        => 'Wrapped',
    'library.stage_submissions' => 'Submissions',
    'library.stage_voting'      => 'Voting',
    'library.stage_results'     => 'Results',
    'library.progress_label'    => 'Contest progress',
    'library.accepting'         => 'Accepting submissions',
    'library.submissions_open'  => 'Submissions open',
    'library.submissions_soon'  => 'Submissions open soon',
    'library.submissions_close' => 'Submissions close',
    'library.voting_open'       => 'Voting open',
    'library.voting_opens'      => 'Voting opens',
    'library.voting_soon'       => 'Voting opens soon',
    'library.voting_closes'     => 'Voting closes',
    'library.between_rounds'    => 'Submissions closed — voting to be scheduled',
    'library.awaiting_results'  => 'Voting closed — awaiting results',
    'library.results_published' => 'Results published',
    'library.winner_announced'  => 'Winner announced',
    'library.see_results'       => 'See full results',
    'library.cast_votes'        => 'Cast your votes',
    'library.be_entry'          => 'Be entry #{n}',
    'library.turnout_hint'      => 'Voter turnout relative to the field',
    'library.none'              => 'No contests yet — check back soon.',

    /* ---------- Archive page ---------- */
    'archive.title'             => 'Archive',
    'archive.search'            => 'Search title or artist…',
    'archive.all_contests'      => 'All contests',
    'archive.loading'           => 'Loading…',
    'archive.no_matches'        => 'No tracks match.',
    'archive.load_failed'       => 'Could not load the archive right now.',

    /* ---------- "Contest Submissions" in the account area ---------- */
    'portal.heading'            => 'Contest Submissions',
    'portal.none'               => 'You haven\'t submitted anything to a contest yet.',
    'portal.see_contests'       => 'See open contests →',
    'portal.untitled'           => '(untitled)',
    'portal.unknown_contest'    => '(unknown contest)',
    'portal.status_needs_audio' => 'Needs audio file',
    'portal.status_rejected'    => 'Rejected',
    'portal.votes_one'          => 'vote',
    'portal.votes'              => 'votes',
    'portal.replacement_pending' => 'replacement pending review',
    'portal.view'               => 'View',
    'portal.why'                => 'Why:',
    'portal.no_reason'          => 'No reason recorded',
    'portal.song_title'         => 'Song title:',
    'portal.artist'             => 'Artist name:',
    'portal.save_details'       => 'Save details',
    'portal.finish_upload'      => 'Finish your entry — upload your audio file:',
    'portal.replace'            => 'Wrong file? Upload a replacement:',
    'portal.replace_again'      => 'Upload a different replacement:',
    'portal.complete_submission' => 'Complete submission',
    'portal.upload_replacement' => 'Upload replacement',
    'portal.saving'             => 'Saving…',
    'portal.saved'              => 'Saved.',
    'portal.save_failed'        => 'Save failed.',
    'portal.save_offline'       => 'Save failed — check your connection and try again.',
    'portal.choose_file'        => 'Choose a file first.',
    'portal.uploading'          => 'Uploading…',
    'portal.uploaded'           => 'Uploaded — pending review.',
    'portal.upload_failed'      => 'Upload failed.',
    'portal.upload_offline'     => 'Upload failed — check your connection and try again.',
];
