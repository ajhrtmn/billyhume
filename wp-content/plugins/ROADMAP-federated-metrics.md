# ROADMAP — Federated metrics across distributed Own Ur Shit instances

Design pass only. Nothing in this document is built — see VISION.md's own round-three pillar section for the hard constraint this design has to satisfy, stated by AJ live and verbatim: **"stripped of specific identities and a fair and transparent way of expressing the data."** This doc exists so that constraint has a real, concrete shape to be built against later, instead of being re-derived from scratch the next time this gets picked up.

## 0. What "federated metrics" is actually for

Once `OUS_Metrics` exists on every instance (it does — shipped this pass), the natural next question is "how's the whole ecosystem doing," not just one artist's own numbers. That's a legitimate thing to want: it's the kind of aggregate health signal that helps the *project* (not any one artist) understand adoption, and it's the kind of number an artist might genuinely be proud to see themselves contributing to ("part of a network of 40 independent artists who moved 12,000 votes last month" is a real, motivating fact).

It is also, unmodified, the exact shape of surveillance this whole ecosystem's anti-middleman stance exists to oppose. A central server quietly compiling every instance's real usage data is Google Analytics with extra steps. The rest of this document is about closing that gap specifically, not about whether federated metrics should exist at all — AJ already said "sounds good" to the *idea*; this is the part that makes the idea safe to build.

## 1. What gets sent, and what never does

**Sent** (only if opted in — see Section 3): per-event-type, per-month aggregate counts. Nothing else.

```json
{ "event_type": "bhc/enroll", "month": "2026-07", "count": 5 }
```

**Never sent**: user IDs, session/client IDs, IP addresses, any `bhcore_events.payload`/`context` field content, raw per-visitor rows, anything that could be joined back to a specific person. This is the same posture `OUS_Metrics` already takes for its own local dashboard (see that class's own docblock) — federation doesn't loosen it, it just moves the same rule across a network boundary.

## 2. The small-instance re-identification problem (the part a naive design misses)

"Aggregate-only" isn't automatically anonymous. An instance with 3 total users submitting "1 vote cast this month" is functionally identifying — a small enough denominator turns an aggregate count back into a fact about one specific person, by elimination. Naive "just count things" federation fails AJ's own "fair" requirement exactly where it matters most: the smallest, least-resourced artists, who are the whole reason this ecosystem exists.

Concrete mitigation, not just a caveat: **an instance's own per-type monthly count is only ever included in the network total if it clears a minimum threshold (a real number to tune, starting proposal: 10)**. Below that, the instance's real count still isn't submitted for that type/month at all — it contributes zero rather than a small, identifying number. This is k-anonymity's actual mechanism (suppress the outlier row, don't approximate it), not a workaround — it should be enforced on the SENDING instance's own side, before anything leaves that server, not trusted to the aggregator to redact after the fact.

## 3. Opt-in, not opt-out

A real toggle on `OUS_Metrics`'s own dashboard, **off by default**. Turning it on shows the exact payload shape (Section 1) before the first submission ever happens — no surprise telemetry, no "you technically agreed in a terms-of-service paragraph." An instance owner can turn it off at any time; the aggregator has no mechanism to keep counting an instance that opted out (its last-known contribution just stops updating, same as any other API integration a user disables).

## 4. "Fair and transparent" as a real technical property, not a slogan

Three concrete requirements, each independently checkable by someone who isn't Own Ur Shit's own maintainer:

1. **The submission code is auditable** — it already is, by construction: whatever computes and sends the payload lives in this same open-source plugin, same as everything else here. Nothing new needed here beyond keeping it that way.
2. **The aggregation math is simple enough to reproduce.** The aggregator does exactly one operation per event-type/month: sum the qualifying (threshold-cleared) submissions across every opted-in instance. Not a weighted average, not a "quality score," not anything a third party couldn't recompute by hand from the same public inputs. Anything fancier than SUM() is a place bias or manipulation could hide.
3. **The public output is the same to everyone, including the instances that contributed to it.** A network-wide dashboard (e.g. "184 courses completed across 41 participating instances this month") is a public page, not a members-only report — the same "fair" that made AJ's own constraint land also means an instance shouldn't have to ask permission to see the number its own data helped build.

## 5. Who runs the aggregator

Deliberately unresolved here, flagged rather than defaulted: this ecosystem's existing posture for cross-instance coordination (bh-registry's own artist directory, the planned ActivityPub federation layer) is "protocol, not platform" — nobody has to trust one company-run server to participate. The honest options for federated metrics are the same shape of choice:

- **A single reference aggregator** (simplest to build first, but reintroduces a "trust this one server" dependency — needs to be justified as a bootstrap step, not a permanent architecture).
- **A real protocol** (any instance can stand up its own aggregator; instances choose which one(s) to report to, the same way a Mastodon server chooses which relay to federate through). More faithful to this ecosystem's whole stance, meaningfully more work, and not worth over-designing before a single instance's opt-in submission code even exists.

Recommendation for whenever this is actually picked up: build the single-reference-aggregator version first (Sections 1–4 fully apply regardless), but do not let its existence become an unexamined assumption that it's the only aggregator that will ever exist — the opt-in toggle should let an instance point at a different aggregator URL from day one, even if only one is running at first.

## 6. Explicitly not scoped by this document

- The actual aggregator service itself (where it runs, what it's built on) — a real infrastructure decision, not a code-design one, and not worth making until Sections 1–4's client-side shape is validated.
- Any per-instance public attribution ("Instance X contributed Y") — bh-registry's own opt-in directory is the correct place for an instance to choose to be publicly named, not something federated metrics should default to linking on its own.
- Historical backfill of existing local `bhcore_events` data into a first federated submission — day-one federation should start counting from whenever an instance opts in, not reach backward into data collected before this design existed.
