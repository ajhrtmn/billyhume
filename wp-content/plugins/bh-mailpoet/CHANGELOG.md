# Changelog — BH MailPoet

Newest first, one short entry per release. The full detail of each change is in git.

1.2.2 - Shorter plugin description; condensed changelog.

1.2.1 - Core now lists this plugin (with MailPoet as its dependency); its own registry entry was removed.

1.2.0 - Robustness pass over every MailPoet call (missing lists, unsubscribed contacts, API errors logged instead of swallowed).
1.1.4 - Verified the sync against a real MailPoet install.
1.1.3 - Registry entry fix (since removed).
1.1.2 - Fix: removing a contact from the CRM now removes them from the MailPoet list too.
1.1.1 - Native PHP types (PHPStan level 6 clean).
1.1.0 - Registered as the optional "email broadcast" provider behind core's campaigns.
1.0.0 - First release: copies People (CRM) contacts into a MailPoet list.
