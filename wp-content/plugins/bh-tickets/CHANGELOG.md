# Changelog — BH Tickets

Newest first, one short entry per release. The full detail of each change is in git.

1.1.1 - Admin-screen wording moved into `words.php`; output unchanged. Shorter comments.

1.1.0 - Support form and Support tab wording moved into `words.php` (`BHT_Words`), including ticket status names. Core now lists the plugin itself.

1.0.6 - The `[bht_support]` wrapper is a `<div>`, so the live theme's section padding doesn't apply to it.
1.0.5 - The Support form got its own stylesheet on the site's color tokens.
1.0.4 - New `[bht_support]` shortcode: the contact form for a Support page; guests' messages notify staff, members' become tickets.
1.0.3 - Fixed a long-failing Test Runner test for `for_user()`.
1.0.2 - Staff replies now reach the person (notification + email), closing a gap where tickets were one-way.
1.0.1 - Native PHP types across all files (PHPStan level 6 clean).
1.0.0 - First release: tickets opened from the account page, staff triage in wp-admin.
