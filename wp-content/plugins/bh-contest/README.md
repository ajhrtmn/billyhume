# BH Contest

Music contests: song submissions, voting, judging, and a live results reveal for streaming. Wording people see is in `words.php`.

## What it does

- **Contests** with submission and voting windows, per-contest branding, and which contact fields entrants must give. Format per contest: public voting, judge scoring, or both (two leaderboards, Judges' Pick and People's Choice, never blended). Optional 1–4 elimination rounds.
- **Voting** with per-category limits (a base amount plus a bonus for entering); ties share a rank.
- **Judging**: an admin-defined rubric, a judge list, and a `[bh_judge_panel]` scoring page; judges never see each other's scores.
- **Anti-fraud signal**: IP + first-party cookie per vote, shown as suspicious clusters on the Results console for a human to review. Nothing is blocked automatically.
- **Live Console**: one admin screen for running a contest live (votes, entrants, CSV export, reveal controls).
- **Reveal Party** (`[bh_results_reveal]`): a public display driven from Live Console, revealed category by category.
- **Contests page** (`[bh_contest_library]`) and **Archive** (`[bh_archive]`): every contest as a card, and a searchable library of entries with winner badges.
- **Discord** announcements (optional webhook).

## Setup

Needs the core plugin (`the-self-hosted-self`) active. Create a contest under **Contests** in wp-admin; its player page is created when you publish. The Reveal Party, Archive and Contests pages are created automatically.

## Testing

Debug Tools → BH Contest seeds fake submissions and votes on a test contest; everything runs on the same code path as a real contest, and reset removes only the fake data.
