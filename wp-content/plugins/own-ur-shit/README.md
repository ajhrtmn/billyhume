# Own Ur Shit

The ecosystem core, plus its hub: shared accounts/profiles/email
verification, shared design tokens with a Storybook-patterned live
preview gallery, and one dashboard for installing and activating BH
Contest, BH Streaming, and BH CRM on top of it.

## What it does

- **Shared identity**: registration, login, session, and email
  verification that BH Contest, BH Streaming, and BH CRM all build on
  instead of each maintaining their own.
- **Shared design tokens + Style gallery**: one place to set colors,
  typography, and spacing; any plugin registers its own live-previewed
  "story" into the same gallery via a filter (`bhy_style_surfaces`).
- **A status dashboard**: shows every known plugin's state (not
  installed / installed but inactive / active), with a version number
  once active and a quick link into its own admin screens.
- **Guided activation**: click "Activate" on anything, and its
  dependencies get installed and activated first, automatically, in the
  correct order. There's also an "Install & Activate Everything" button
  for getting from zero to fully running in one click.
- **Two install sources, handled transparently**: this plugin's own
  siblings (bundled as inert zips inside its own folder — see below) get
  extracted locally; a third-party dependency (WooCommerce, say) gets
  installed live from WordPress.org using the same core APIs the
  "Install Now" button in wp-admin itself uses.
- **Extensible by design, in two ways**: a future plugin can register a
  rich dashboard card via a filter (`ous_registered_plugins`) with zero
  changes to this plugin's code, or opt into a minimal auto-discovered
  card with zero code at all — just a `Ecosystem: Own Ur Shit` line in
  its own plugin header.

## Important: this one is required

**This is the one plugin everything else depends on.** BH Contest, BH
Streaming, and BH CRM each check for it on `plugins_loaded` and show an
admin notice (rather than fatal-erroring) if it's missing or inactive.
The relationship is one-directional the other way, though: this plugin
has no dependency on any of them, and works fine — just with an empty
dashboard — with zero feature plugins installed.

## Installation

Two ways to get all four plugins, depending on how you're deploying:

1. **Standard "Upload Plugin" button** (wp-admin → Plugins → Add New →
   Upload Plugin): upload this plugin's own zip. It's structurally a
   single valid plugin — the other three ship as inert zip files inside
   its own folder — so the normal upload flow accepts it without
   complaint. Once active, use the dashboard to install and activate the
   rest.
2. **FTP / server file manager**: extract the flat multi-folder bundle
   directly into `wp-content/plugins/` — every plugin lands exactly
   where WordPress expects, no dashboard step needed before they're all
   visible in the Plugins list.

Either way, activate this plugin **first** — BH Contest, BH Streaming,
and BH CRM all require it active before their own features light up.

## License

Licensed under the GNU Affero General Public License v3.0 (AGPL-3.0). See
[LICENSE](./LICENSE) for the full text.
