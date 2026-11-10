# AI Wire — Production Readiness Audit

**Audit date:** 4 November 2026  
**Note:** Historical snapshot of the 0.2.0 hardening work. Current operator docs are the [README](../README.md), [Social Distribution Architecture](SOCIAL-DISTRIBUTION-ARCHITECTURE.md) (X, Instagram Reels, YouTube Shorts as shipped in 0.2.54), and [What it costs to run](PLATFORM-COSTS.md). The plugin is a generic WordPress install, not a single-publisher pipeline.  
**Audited baseline:** merged PR #7 plus the production-hardening branch changes for version 0.2.0.  
**Architecture:** WordPress/PHP orchestration with two sequential AI roles (Writer → Reviewer). This is not an autonomous multi-agent framework.

## Executive assessment

The plugin is a controlled editorial drafting pipeline. It ingests RSS/Atom entries, stores a deduplicated queue item, asks a Writer model for an original brief, sends that brief and the source to a separate Reviewer pass, and creates a WordPress **draft** only when the review gates pass. Publishing remains a human action.

The current mainline implementation before this hardening branch was **not production-ready**. The hardening branch addresses several concrete failure modes: manual runs no longer execute inside the admin request, the Writer and Reviewer calls run in separate queue ticks, stranded new queue rows are resumed, retries receive their own scheduled events, transient and permanent API failures are separated, draft creation is recoverable after a crash, lock heartbeat handling is corrected, and public-view throttling uses an atomic database upsert.

Even after these changes and passing CI, a live production deployment cannot be certified from repository tests alone. A staging upgrade against a production-like database, real system-cron verification, host/network validation and controlled canary are still required.

## What the system does

1. WordPress schedules a run or an administrator enqueues one from the dashboard.
2. PHP obtains a database-backed run lock and loads feed, token and spend limits.
3. Configured RSS/Atom feeds are fetched; source URLs are validated, source text is bounded, and a SHA-256 source key prevents duplicate queue records.
4. A stable experiment ID and A/B writer variant are assigned to each source item.
5. The Writer returns structured article JSON. The Reviewer independently evaluates source grounding, novelty, usefulness, attribution, similarity risk and policy flags.
6. Only a passing review creates a sanitized WordPress **draft**. The plugin has no auto-publish path.
7. Published experiment articles emit a GA4/GTM event and a first-party engaged-read event after three visible seconds.

## Changes in the production-hardening branch

| Area | Change | Why it matters |
|---|---|---|
| Manual execution | Admin action schedules background work and redirects immediately | Avoids keeping an administrator's browser request open through feeds and model calls |
| AI execution slices | Writer saves its output and yields; Reviewer runs on a later cron tick | Avoids combining two model calls into one long PHP request |
| Queue recovery | The queue scan now includes stranded new items, even when feed settings have changed | Recovers an item inserted before an interrupted worker could begin processing it |
| Retry scheduling | Retry events are scheduled at the retry deadline with unique event arguments | Avoids waiting for the next hourly run and avoids WordPress's duplicate-event suppression |
| API failures | HTTP 408/409/429/5xx and transport failures remain retryable; other HTTP 4xx errors become actionable terminal errors | Prevents wasting retries on invalid credentials, invalid requests or unavailable models |
| API timeout | Per-request timeout is 45 seconds with `reasoning.effort` set to `none` | GPT-6 Luna/Terra default to medium reasoning, which would consume `max_output_tokens` and miss a short HTTP timeout; host-level timeouts still need staging validation |
| Draft recovery | Before creating a post, the plugin looks for a post carrying the queue item's queue ID metadata | Prevents duplicate drafts if PHP dies after post insertion but before the queue row is updated |
| Lock heartbeat | Sub-second heartbeat avoids false failure when multiple items begin in the same Unix second | Prevents valid runs from stopping because update_option() reported no change |
| Stale-lock recovery | Deletes the exact serialized stale option value conditionally | A concurrent heartbeat changes the value and prevents deletion of a live lock |
| View throttling | New schema-v5 table and atomic SQL upsert; only an HMAC of IP + post is persisted | Removes the transient read/modify/write race and avoids storing raw IP addresses |
| Release version | Plugin header and internal version are aligned at 0.2.0 | Makes installed version visible and consistent |
| Schema upgrades | `maybe_upgrade_schema()` now calls `ensure_schema()` instead of `activate()` | Prevents an upgrade from wiping retry/continue/manual cron events |
| GPT-6 reasoning | Writer/reviewer requests set `reasoning.effort` to `none` | Stops medium-reasoning token use from starving `max_output_tokens` and timing out |
| Incomplete responses | HTTP 200 with `failed`/`incomplete`/`cancelled` status is retryable | Avoids treating truncated model output as valid JSON |

## Automated validation added

The WordPress integration workflow boots WordPress against MySQL and tests:

- Required queue, view and rate-limit tables are created; a simulated v4→v5 migration preserves existing queue rows.
- A lock heartbeat succeeds within the same second, and an old worker cannot overwrite a replacement lease.
- Writer and Reviewer are executed as separate queue steps and produce one draft.
- A draft is not duplicated when the queue update is simulated as interrupted after post creation.
- Transient errors are moved to retry_wait and a matching retry event is scheduled.
- An API authentication failure is classified as permanent and uses the configured bounded timeout.
- Incomplete API responses are retryable; 429s remain retryable; drafts are never published; invalid/unpublished view tokens are rejected.
- Schema upgrades preserve scheduled retry events; consecutive lock heartbeats keep ownership.
- The public view endpoint enforces the database-backed per-IP/post rate limit.

The existing PHP lint workflow remains in place for PHP 7.4, 8.1 and 8.3. Integration tests run on PHP 8.2 and 8.3. Release packaging now depends on a successful WordPress integration validation job.

## Remaining production gates

These are intentionally not described as solved by the code changes above:

1. **Staging migration:** install/upgrade version 0.2.0 against a copy of the current production database; verify dbDelta adds the new rate-limit table without changing queue/view history.
2. **Cron reliability:** configure a real host cron to invoke WordPress cron. WP-Cron remains traffic-triggered and a scheduled event is not a durable external queue.
3. **Host execution limits:** verify the 45-second API timeout, feed-fetch behavior, PHP max_execution_time, reverse-proxy timeout and loopback cron execution on the actual host.
4. **Concurrency/load:** run overlapping cron/manual triggers and parallel view requests on staging; verify lock ownership, retry behavior, queue uniqueness and rate-limit behavior.
5. **Feed diagnostics:** feed fetch failures are still skipped rather than surfaced through a proper health/error dashboard.
6. **Operational observability:** no alerting integration, durable external worker, or complete last-run/next-run health panel exists.
7. **Privacy and abuse:** the daily HMAC token is public in article HTML. Atomic IP/post throttling reduces replay floods but does not prove a human read, and proxy configurations can make many visitors share one REMOTE_ADDR.
8. **Analytics validity:** first-party counts are directional only. They are not impressions, unique people, sessions, revenue or a statistically valid A/B result.
9. **Retention:** queue source/rewrite/review payloads and operational records still need an explicit retention policy.
10. **Editorial quality:** the model Reviewer is not a substitute for a human fact-checker, legal review, rights review or editorial approval.

## Required staging acceptance

Before enabling automatic feed processing on production:

- [ ] Take a database and files backup; verify a restore path.
- [ ] Upgrade a staging clone with existing queue rows and view counters.
- [ ] Confirm all three plugin tables exist and old records remain intact.
- [ ] Configure one trusted feed and a low daily/monthly budget.
- [ ] Enqueue a manual run and confirm the admin request returns promptly.
- [ ] Verify Writer → Reviewer → WordPress draft, including rejected and malformed outputs.
- [ ] Force a 429/5xx and confirm a scheduled retry; force a 401 and confirm a terminal actionable error.
- [ ] Simulate interruption after post insertion and confirm retry/recovery does not create a second post.
- [ ] Trigger overlapping cron/manual runs and verify one active processor.
- [ ] Verify public-view token rejection and rate limiting behind the actual CDN/proxy.
- [ ] Confirm GA4/GTM event behavior with session storage enabled and disabled.
- [ ] Verify real system cron runs reliably when the site has no visitor traffic.
- [ ] Observe a small canary feed set for several days before increasing volume.

## Final verdict

**Code status:** the hardening branch materially improves reliability and adds automated WordPress integration coverage.  
**Production status:** not certified until the branch is merged, CI passes, and the staging acceptance checklist is completed on the target host.  
**Publishing safety:** remains drafts-only; human review is required.
