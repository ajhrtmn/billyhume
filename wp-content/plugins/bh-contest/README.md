# BH Contest

Music contest voting — submissions, category-based voting with
configurable per-category vote limits, a live results reveal system
built for streaming, and Discord integration for announcements.

## What it does

- **Contests**: admin-configurable submission and voting windows,
  per-contest branding overrides, and configurable contact-info
  requirements (which fields a submitter must provide, per contest).
- **Voting**: per-category vote limits (a base amount plus a bonus for
  submitting a track), with proper tie handling — ties share a rank,
  Olympic-medal style, rather than an arbitrary tiebreak.
- **Live Console**: a private, contest-scoped dashboard tying together
  participant identity info, live vote counts, CSV export, and the
  Results Reveal controls in one place — built specifically for running
  a contest live on stream.
- **Results Reveal**: a controller (private, in Live Console) and a
  public display (`[bh_results_reveal]`) that stay in sync over polling
  — the controller can be on a completely different machine from
  whatever's doing the OBS capture. Reveals build suspense
  category-by-category, medal-style.
- **Archive**: `[bh_archive]` — a unified, searchable library across
  every contest ever run, with winner badges once a contest's results
  are published.
- **Discord**: automatic notifications (new entry approved, voting
  opened) plus a manual "announce anything, right now" box — every
  message is a rich embed, not plain text.

## Requirements

- **Own Ur Shit** (the ecosystem core — shared accounts/profiles/email
  verification and the design tokens this plugin's own stylesheet is
  built on)

It needs to be installed and active first — this plugin will show a
clear admin notice and refuse to load its own features otherwise (the
dependency check happens on `plugins_loaded`, so it's reliable
regardless of which plugin's folder name happens to sort first
alphabetically).

## Installation

1. Install and activate **Own Ur Shit** first.
2. Install and activate this plugin.
3. Create a contest under **Contests** in wp-admin.
4. The `[bh_contest_player]` shortcode page gets created automatically
   when you publish a contest — find its link on the contest's own edit
   screen.
5. `[bh_results_reveal]` and `[bh_archive]` pages ("Reveal Party,"
   "Archive") get created automatically the first time you're in
   wp-admin after this plugin is active.

## For testing

Use **Debug Tools** under Contests to seed fake submissions and votes on
a test contest — the reveal, Discord notifications, and everything else
run on the exact same code path against seeded data as they do against
a real contest, so a rehearsal is a genuine rehearsal, not a simulation.
