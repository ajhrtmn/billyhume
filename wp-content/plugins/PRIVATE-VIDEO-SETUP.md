# Private (signed) course video — setup

For paid course video that must not be a shareable link. Both options
below give the player a URL that **expires in 4 hours and is minted per
viewer** after bh-courses' own access gate has already passed. Configure
one or both in **wp-admin → Self-Hosted Self → Media & CDN Setup →
"Private (signed) video delivery"**, then pick the source on any Video
step in a lesson.

---

## Option A — Cloudflare R2 + Worker  (near-free, keeps every feature)

R2: first 10 GB storage free, then $0.015/GB, **no egress fees**. Worker
free tier = 100k requests/day. Renders as a real `<video>`, so chapters
(markers included), overlays and the watch threshold all work exactly
like an uploaded file.

1. **Bucket.** Cloudflare dashboard → R2 → create a bucket, e.g.
   `billyhume-course-video`. Keep it private (no public access). Upload
   your video files; the *key* is the path you'll paste into the step,
   e.g. `courses/lesson-12/master.mp4`.
2. **Worker.** Workers & Pages → Create Worker. Paste
   `the-self-hosted-self/tools/r2-video-worker.js` as its code (or deploy
   with `wrangler` — the `wrangler.toml` is in that file's header).
3. **Bind the bucket** to the Worker as `VIDEOS`
   (Worker → Settings → Variables → R2 Bucket Bindings).
4. **Add a Secret** named `SIGNING_SECRET` (Settings → Variables → Add →
   type "Secret") — a long random string.
5. **WordPress.** Media & CDN Setup → "Cloudflare R2 + Worker":
   - Worker URL = your Worker's URL (e.g.
     `https://course-video.<subdomain>.workers.dev`)
   - Signing secret = the **same** value as `SIGNING_SECRET`
6. In a lesson's Video step, choose **"Cloudflare R2 (private, signed)"**
   and enter the object key.

**Faststart still matters:** upload MP4s muxed with
`ffmpeg -i in.mp4 -c copy -movflags +faststart out.mp4` so playback
starts immediately.

---

## Option B — Bunny Stream  (turnkey, ~$1–10/mo, adaptive)

Bunny's own CDN + adaptive HLS (also cures the Bluehost buffering).
Cross-origin iframe, so bh-courses drives it through Bunny's player.js —
chapter **list** (clickable, active-highlighted), pausing overlays and
the watch threshold all work; the only thing missing is markers painted
on Bunny's own scrub bar.

1. **Library.** Bunny dashboard → Stream → create a library. The
   **Library ID** is the number in its URL.
2. **Token auth.** In the library → **API** tab, turn on
   *Token Authentication* and copy the **Token Authentication Key**.
3. **Upload** your videos to the library. Each gets a **GUID** (a UUID) —
   that's what goes in the step.
4. **WordPress.** Media & CDN Setup → "Bunny Stream": Library ID +
   Token Authentication Key.
5. In a lesson's Video step, choose **"Bunny Stream (private)"** and
   enter the video GUID.

### Chapters on Bunny

They come from bh-courses' own step editor (Chapters section) — no need
to add them in Bunny. Authoring: since the editor has no playable
preview for a signed source, type the timestamps rather than scrubbing.
The list renders under the video and seeks on click.

---

## Notes

- Both sources store only the id/key in the lesson — the library/secrets
  are site-wide, so moving a course between sites is just a re-key.
- An option only appears in the step editor once its provider is
  configured.
- If a link ever 403s in the player, it expired — reloading the lesson
  mints a fresh one.
- `BHY_MediaToken`'s signing is covered by the core Test Runner suite
  (Self-Hosted Self → Debug Tools → Tests). The R2 signature is verified
  byte-for-byte against the Worker's HMAC. End-to-end playback itself
  can only be checked against a real bucket/library.
