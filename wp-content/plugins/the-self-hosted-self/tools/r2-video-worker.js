/**
 * r2-video-worker.js — private, signed video delivery from a Cloudflare
 * R2 bucket, for BHY_MediaToken::sign_r2() (class-media-token.php).
 *
 * WHY: object storage + offload (Advanced Media Offloader / OUS_MediaWizard)
 * only makes a bucket file *public*. Course video that's for sale needs a
 * link that expires and can't be reused off-site. This Worker sits in
 * front of a PRIVATE bucket, checks an HMAC that WordPress mints per
 * enrolled viewer, then streams the object with byte-range support (so
 * seeking/scrubbing works). No bandwidth fees on R2.
 *
 * ── DEPLOY ────────────────────────────────────────────────────────────
 * 1. Create a private R2 bucket, e.g. `billyhume-course-video`, and
 *    upload your videos into it (key = the path you'll paste into a
 *    bh-courses "R2 (signed)" step, e.g. `courses/lesson-12/master.mp4`).
 * 2. Create a Worker (dashboard → Workers & Pages → Create) and paste
 *    this file as its code, OR use wrangler with the wrangler.toml below.
 * 3. Bind the bucket to the Worker as `VIDEOS`
 *    (Settings → Variables → R2 Bucket Bindings).
 * 4. Add a Secret named `SIGNING_SECRET` (Settings → Variables → Add,
 *    type "Secret") — a long random string.
 * 5. In WordPress: Self-Hosted Self → Media & CDN Setup → "Private
 *    (signed) video delivery" → set the Worker URL and the SAME
 *    SIGNING_SECRET value.
 *
 * wrangler.toml:
 *   name = "course-video"
 *   main = "r2-video-worker.js"
 *   compatibility_date = "2024-11-01"
 *   [[r2_buckets]]
 *   binding = "VIDEOS"
 *   bucket_name = "billyhume-course-video"
 *   # then:  npx wrangler secret put SIGNING_SECRET
 *
 * ── TOKEN CONTRACT (must match BHY_MediaToken::sign_r2) ───────────────
 *   sig = base64url( HMAC-SHA256(SIGNING_SECRET, `${objectKey}:${exp}`) )
 *   GET {workerUrl}/{objectKey}?exp={unixSeconds}&sig={sig}
 */

export default {
  async fetch(req, env) {
    if (req.method !== "GET" && req.method !== "HEAD") {
      return new Response("Method not allowed", { status: 405 });
    }

    const url = new URL(req.url);
    const key = decodeURIComponent(url.pathname.replace(/^\/+/, ""));
    const exp = url.searchParams.get("exp");
    const sig = url.searchParams.get("sig");

    if (!key || key.includes("..") || !exp || !sig) {
      return new Response("Missing or invalid params", { status: 400 });
    }
    if (!/^\d+$/.test(exp) || Date.now() / 1000 > Number(exp)) {
      return new Response("Link expired", { status: 403 });
    }
    if (!(await validSignature(env.SIGNING_SECRET, `${key}:${exp}`, sig))) {
      return new Response("Bad signature", { status: 403 });
    }

    const rangeIn = req.headers.get("Range");
    const obj = await env.VIDEOS.get(key, rangeIn ? { range: parseRange(rangeIn) } : undefined);
    if (!obj) return new Response("Not found", { status: 404 });

    const headers = new Headers();
    obj.writeHttpMetadata(headers);
    if (!headers.has("Content-Type")) headers.set("Content-Type", "video/mp4");
    headers.set("Accept-Ranges", "bytes");
    headers.set("Cache-Control", "private, no-store");
    if (obj.httpEtag) headers.set("ETag", obj.httpEtag);

    // 206 for a satisfied range request
    if (rangeIn && obj.range) {
      const start = obj.range.offset ?? 0;
      const length = obj.range.length ?? (obj.size - start);
      headers.set("Content-Range", `bytes ${start}-${start + length - 1}/${obj.size}`);
      headers.set("Content-Length", String(length));
      return new Response(req.method === "HEAD" ? null : obj.body, { status: 206, headers });
    }

    headers.set("Content-Length", String(obj.size));
    return new Response(req.method === "HEAD" ? null : obj.body, { status: 200, headers });
  },
};

async function validSignature(secret, message, provided) {
  const enc = new TextEncoder();
  const cryptoKey = await crypto.subtle.importKey(
    "raw", enc.encode(secret || ""), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]
  );
  const mac = await crypto.subtle.sign("HMAC", cryptoKey, enc.encode(message));
  const expected = base64url(mac);
  // length-independent comparison
  if (expected.length !== provided.length) return false;
  let diff = 0;
  for (let i = 0; i < expected.length; i++) diff |= expected.charCodeAt(i) ^ provided.charCodeAt(i);
  return diff === 0;
}

function base64url(buf) {
  let s = "";
  const bytes = new Uint8Array(buf);
  for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
  return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function parseRange(header) {
  const m = /^bytes=(\d*)-(\d*)$/.exec((header || "").trim());
  if (!m) return undefined;
  const start = m[1] === "" ? undefined : Number(m[1]);
  const end = m[2] === "" ? undefined : Number(m[2]);
  if (start === undefined && end !== undefined) return { suffix: end };        // last N bytes
  if (start !== undefined && end !== undefined) return { offset: start, length: end - start + 1 };
  if (start !== undefined) return { offset: start };
  return undefined;
}
