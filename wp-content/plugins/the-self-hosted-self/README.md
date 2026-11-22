# Site Core (the-self-hosted-self)

The one plugin every other plugin on billyhume.net needs. Its wp-admin menu is **Site Tools**. The folder and class names are historical; don't rename them (the live site's updates and saved settings depend on them).

## What it provides

- **Accounts and the `/account/` page**: log in, sign up, email confirmation, optional two-factor codes, and the tabs other plugins add (My Courses, Contest Submissions, Support, Membership).
- **Design Suite**: colors, fonts and spacing as design tokens, edited live in **Appearance → Customize → Design Suite**. See `../STYLE-SYSTEM.md`.
- **Debug Tools** (Site Tools → Debug Tools): error log, Test Runner, job queue, API docs, GitHub Updates, and each plugin's own debug section.
- **Shared services** other plugins call instead of building their own: notifications (`OUS_Notifications`), background jobs (`OUS_Jobs`), email (`BH_Mail`), event tracking (`BH_Event`), the WooCommerce bridge (`BH_Commerce`), block content (`BH_Content`/`BH_Studio`), table names (`OUS_Tables`).
- **Site Tools overview**: each plugin's status, version and update state.
- **GitHub Updates**: installs plugin updates from the `dev` branch on GitHub (see `../RUNBOOK-live-updates.md`).
- **Wording** for the account page lives in `words.php` in this folder.

## Dependencies

Every other plugin checks for this one on `plugins_loaded` and shows an admin notice instead of crashing if it's missing. This plugin never depends on any of them.
