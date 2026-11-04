# TechnoRapper AI Wire

A WordPress plugin that turns configured RSS/Atom items into source-attributed, AI-assisted editorial drafts. Each item passes a writer and a separate editorial review. Only passing items become **WordPress drafts**; a human remains responsible for publishing.

> **Safety default:** this plugin does not auto-publish. AI review is a quality gate, not a guarantee of factual accuracy or legal clearance.

For a role-by-role execution diagram, queue-state explanation and code-level feature audit, see [AI Agent Workflow and Feature Audit](docs/AI-AGENT-WORKFLOW-AUDIT.md).

## End-to-end pipeline

The diagram below shows the normal scheduled/manual path, the editorial gate, and the reader-measurement path. The processing interval and per-run workload cap are selected in the WordPress **AI Wire** dashboard.

```mermaid
flowchart TD
    A["Configurable WP-Cron schedule<br/>or administrator runs now"] --> B["Acquire database-backed run lock"]
    B --> C{"Lock acquired?"}
    C -- No --> C1["Exit; another run is active"]
    C -- Yes --> D["Load feed settings, run cap and usage limits"]
    D --> E["Fetch configured RSS / Atom feeds"]
    E --> F["Inspect up to 10 recent items per feed<br/>within the per-run processing cap"]
    F --> G["Validate URL, normalize and truncate source"]
    G --> H{"Already seen source key?"}
    H -- Yes --> F
    H -- No --> I["Assign stable experiment ID + A/B variant"]
    I --> J["Persist item in queue"]
    J --> K["Writer model creates original draft JSON"]
    K --> L{"Writer output valid?"}
    L -- No --> L1["Mark error / hold for retry review"]
    L -- Yes --> M["Independent reviewer checks claims,<br/>novelty, usefulness and policy flags"]
    M --> N{"Review passes every gate?"}
    N -- No --> N1["Quarantine; no post is created"]
    N -- Yes --> O["Sanitize output and insert WP draft"]
    O --> P["Human editor reviews and publishes"]
    P --> Q["Published article emits GA4 event"]
    P --> R["3-second visible-read tracker"]
    R --> S["Signed REST request"]
    S --> T["First-party daily view counter"]
    Q --> U["GA4 Explore / reporting"]
    T --> V["AI Wire A/B dashboard"]
    B --> W["Release lock in finally block"]
```

### Pipeline stages and safety boundaries

| Stage | What happens | Guardrail |
|---|---|---|
| Trigger | Configurable WP-Cron interval or an administrator-triggered run | A database-backed lock serializes runs |
| Ingestion | Fetch configured RSS/Atom feeds and inspect up to 10 recent items per feed | HTTP(S) URL validation, source-key deduplication, source-size cap |
| Assignment | Stable A/B assignment from the source key | Retries keep the same experiment and variant |
| Generation | Writer produces title, lede, body, category and tags | Source content is treated as untrusted input |
| Review | Separate model evaluates factual consistency, novelty, usefulness and policy flags | Fail closed: only explicit passing output proceeds |
| Draft creation | Sanitized content is inserted as a WordPress draft | No automatic publication |
| Measurement | GA4 event plus first-party 3-second visible-read counter | Signed REST payload, per-session browser dedupe, no IP storage |
| Analysis | WordPress dashboard and GA4 Explore | Do not infer revenue or SEO lift from draft counts or a small sample |

## WordPress dashboard configuration

The **AI Wire** menu in wp-admin is the single place to configure credentials, feed ingestion, scheduling, processing limits, model selection, web search and editorial experiments. The API key uses a separate save action; all other settings are saved together. The manual-run action enqueues background work and returns immediately; it does not keep the administrator request open during feed/API processing.

```mermaid
flowchart TD
    A["WordPress admin"] --> B["AI Wire settings"]
    B --> C["OpenAI API key<br/>write-only credential"]
    B --> D["Feeds + cron schedule"]
    B --> E["Daily draft + per-run caps"]
    B --> F["Monthly USD + token limits"]
    B --> G["Writer/reviewer models + tokens"]
    B --> H["Web search + A/B experiment"]
    D --> I["Scheduled or manual run"]
    E --> I
    F --> I
    G --> I
    H --> I
```

## Production-readiness status

The plugin release is **not considered production-validated until the staging acceptance checklist has passed on the target WordPress host**. Version 0.2.0 adds background enqueueing for manual runs, scheduled bounded retries, permanent-vs-transient API error handling, an idempotent draft recovery path, atomic stale-lock deletion/heartbeat handling, and an atomic database-backed public-view rate limiter. The schema migration creates the rate-limit table without dropping existing queue/view data.

Automated WordPress integration tests now exercise selected critical paths. They do not prove the production host's cron runner, outbound networking, database upgrade behavior on a copy of the live database, proxy/IP configuration, or end-to-end analytics. Keep auto-publishing disabled (it is not implemented) and complete the staging runbook before enabling scheduled processing.

## Install and configure

1. Upload the plugin ZIP through **Plugins → Add New → Upload Plugin**, or copy the plugin directory to `wp-content/plugins/technorapper-ai-wire/`.
2. Activate **TechnoRapper AI Wire**. The plugin creates/updates its queue and view tables and schedules its initial processing run.
3. Open **AI Wire** in wp-admin. This is the central configuration screen: enter the API key, choose the processing schedule, add feeds, set daily/monthly limits, choose models, and configure editorial and analytics options.
4. Save the API key in the **OpenAI API connection** section. The key is stored separately in a non-autoloaded WordPress option, never shown again in the form, and can be replaced or removed there. A key supplied through `OPENAI_API_KEY` or `TR_AI_WIRE_OPENAI_API_KEY` in `wp-config.php` remains supported as a fallback. Dashboard key takes precedence.
5. Save the remaining configuration using **Save settings**. Use **Fetch feeds and process queue now** to enqueue a background run. WP-Cron (preferably triggered by a real system cron) executes it.
6. Inspect **AI Wire → Queue & usage**, review generated drafts in WordPress, and publish manually only after editorial approval.

### Dashboard configuration reference

All day-to-day settings are available in one place under **AI Wire**:

| Setting | What it controls |
|---|---|
| OpenAI API key | Credential used for writer and reviewer requests; saved separately from ordinary settings |
| Automatic processing schedule | Hourly, every 3, 6 or 12 hours, daily, or manual-only |
| RSS/Atom feeds | One feed URL per line, optionally followed by `| Feed label` |
| Maximum drafts per day | Daily draft creation cap; `0` pauses draft creation |
| Maximum items per run | Caps the number of queued retries and new feed items processed in one scheduled/manual run (1–100) |
| Monthly API budget | Local estimated USD spend ceiling; `0` stops AI calls |
| Monthly token limit | Combined monthly input/output token ceiling; `0` stops AI calls |
| Maximum output tokens | Per AI pass output ceiling |
| Maximum source characters | Source text truncation limit before AI processing |
| Writer and reviewer models | Model selection for the two editorial passes |
| Independent web search | Optional reviewer corroboration and associated search charges |
| Editorial A/B experiment | Enables/disables stable A/B writer assignment and sets the experiment ID |

The API-key section has its own save action so the secret is not included in the ordinary WordPress settings payload. The other configuration values are saved together with **Save settings**.

### Suggested initial production settings

- Start with 1–3 trusted feeds and a daily cap of 1–3 drafts.
- Set a low monthly API budget and token ceiling; raise only after observing real usage.
- Leave optional web search disabled until you have validated its cost and review output.
- Keep drafts-only workflow. Do not bypass the human editorial review.
- Use a unique experiment ID for each materially different experiment. Do not rename an experiment midway through a reporting window.

## Spend, rate and volume controls

- The daily cap counts drafts created by this plugin on the current UTC day. Set it to `0` to pause draft creation.
- The per-run cap limits total existing `waiting_budget` retries plus newly discovered items attempted in one run. It is a workload/burst control, separate from the daily draft cap.
- Monthly USD and token limits use this plugin's recorded API usage and pre-request estimates. They are not provider-side hard limits and do not cap other apps using the same API project/key.
- Configure a provider-side project budget/rate limit as a second independent control.
- Cost estimates depend on the model rates configured in the source. Verify current provider pricing and update the rate table before changing model IDs or pricing.
- The optional web-search review adds search-tool and token charges.
- If the API call or review fails, the item is held in the queue; inspect errors before retrying. Budget-held items are retried by later runs when budget becomes available.

## A/B experiments and analytics

- Variant A uses the baseline writer prompt; Variant B uses the original editorial-desk prompt.
- Assignment is deterministic per source item and approximately 50/50 over a sufficiently large sample. This is **not** visitor-level randomization or topic-paired testing.
- **AI Wire → A/B experiments** reports queue outcomes for the selected experiment and first-party engaged reads from the last 30 days.
- A published experiment article emits GA4 event `tr_ai_wire_article_view` with `experiment_id`, `variant`, `article_id`, `article_title`, `page_location` and `page_title`.
- In GA4 Admin → Custom definitions, register event-scoped dimensions for `experiment_id`, `variant` and `article_id`. Use Explore to compare event counts, engaged sessions, engagement time and key events.
- First-party reads are a lightweight proxy: the browser waits 3 seconds, requires a visible page, and counts the same article once per browser session. A signed daily token protects the REST endpoint against blind unauthenticated increments; it is not bot-proof or a substitute for analytics fraud filtering.
- If browser session storage is unavailable, the first-party counter intentionally skips the read rather than risk duplicate counts.
- GA4, Search Console and AdSense reports are **not** imported into WordPress. Use GA4/AdSense reporting and Search Console for revenue, organic impressions, clicks and CTR. No revenue or SEO outcome is estimated by this plugin.
- Compare similar topics, publication age and traffic sources over several weeks. Do not select a winner from a handful of posts.

## Production deployment runbook

### Before enabling scheduled processing

- [ ] Deploy to staging first and take a database + files backup.
- [ ] Confirm the site is on a supported PHP version (7.4+) and WordPress 6.2+.
- [ ] Confirm HTTPS, valid TLS, working outbound HTTPS requests and DNS resolution to the API/feed hosts.
- [ ] Save the API key in the AI Wire dashboard (or use a server-level constant as a fallback); verify it is not present in the repository or public diagnostics.
- [ ] Configure a provider-side budget and alerts in addition to plugin limits.
- [ ] Add only trusted feed URLs. Review each feed's publisher rights, attribution requirements and content reuse terms.
- [ ] Confirm the WordPress database user can create/alter the plugin tables.
- [ ] Ensure regular database backups and a tested restore procedure.
- [ ] Configure a real system cron to invoke `wp-cron.php` if traffic-triggered WP-Cron is unreliable.

### Staging acceptance test

1. Activate the plugin and verify both `wp_<prefix>tr_ai_wire_items` and `wp_<prefix>tr_ai_wire_views` tables exist (the actual prefix depends on the site).
2. Save one test feed and run the queue manually.
3. Verify the source item is stored once, with an experiment ID and A/B variant.
4. Verify writer and reviewer outcomes are visible in the queue.
5. Confirm a passing item becomes a **draft**, not a published post; confirm a rejected item is quarantined.
6. Re-run the same feed and confirm the source key prevents duplicate queue items.
7. Test budget exhaustion and verify no further AI calls are made by the plugin.
8. Publish a test article. Verify GA4 event `tr_ai_wire_article_view` in DebugView/Realtime and a first-party count after a visible 3-second read.
9. Verify a missing/invalid view token is rejected and the valid browser fallback sends the same signed payload.
10. Deactivate and confirm scheduled plugin cron events are cleared. Reactivate and verify schema checks do not erase queue data.

### Rollout and rollback

- Roll out to a small feed set and low daily cap first. Watch queue errors, API spend, PHP logs, cron execution and draft quality for several days.
- Increase feed count/caps gradually only after validating cost and editorial quality.
- To pause processing, set the daily cap to `0` and/or remove the API key from server configuration. Deactivation clears the scheduled hook but retains queue data and drafts.
- For rollback, deactivate the plugin and restore the previous plugin package if necessary. Do not drop plugin tables unless you explicitly intend to delete queue and engagement history.
- Keep a record of the plugin version, deployed commit, active experiment ID and settings at each rollout.

## Security and privacy notes

- Admin settings and manual execution are restricted to administrators; manual execution uses a WordPress nonce.
- The dashboard API credential is stored in a dedicated non-autoloaded WordPress option and is never displayed after saving. Server-level constants remain supported as a fallback.
- Feed/source text is untrusted model input. Generated HTML is passed through `wp_kses_post()`; titles and excerpts are sanitized.
- The public view endpoint only accepts published experiment articles with a valid daily HMAC token. The token is not sent to GA4/GTM.
- The view table stores aggregate post/day counters, not IP addresses, cookies, names or user IDs. Browser session storage is used only for per-session deduplication.
- The HMAC token is rendered into the public article page and therefore should be understood as an anti-blind-posting measure, not proof of a human reader. Use edge/WAF rate controls and analytics bot filtering if abuse becomes material.
- Keep WordPress core, PHP, the theme and other plugins patched. Use least-privilege database and hosting credentials.

## Known limitations and operational caveats

- The reviewer is an AI quality gate, not a fact-checking guarantee. Web corroboration can help but cannot certify accuracy.
- The plugin does not auto-publish, import source images, or migrate existing Feedzy jobs/posts.
- WP-Cron is traffic-triggered unless backed by a host system cron.
- Feed caching can delay discovery. Each run inspects up to 10 recent items per configured feed.
- Plugin usage limits are local estimates, not account-wide API hard limits.
- First-party engaged-read counters are approximate and can be blocked by browser privacy settings, ad blockers, JavaScript errors or session-storage restrictions.
- There is no native GA4, Search Console or AdSense data import, revenue attribution, editorial approval workflow, alerting service or remote job dashboard in this release.

## CI and release pipeline

GitHub Actions runs PHP syntax checks on PHP 7.4, 8.1 and 8.3 and a WordPress integration suite on PHP 8.2 and 8.3 with MySQL. Integration tests cover schema creation, queue retry scheduling, same-second lock heartbeats, draft idempotency after an interrupted queue update, API authentication error classification and public-view rate limiting. A separate release workflow builds a WordPress-installable ZIP (with the plugin directory at the archive root), generates a SHA-256 checksum, and uploads both as a workflow artifact. Pushing a `v*` tag also publishes those files as a GitHub Release. Production deployment remains a deliberate manual action; this repository does not hold WordPress credentials.

```mermaid
flowchart LR
    A["Developer change"] --> B["Pull request"]
    B --> C["GitHub Actions"]
    C --> D["PHP 7.4 lint"]
    C --> E["PHP 8.1 lint"]
    C --> F["PHP 8.3 lint"]
    D --> G{"All checks pass?"}
    E --> G
    F --> G
    G -- No --> H["Fix and push"]
    H --> C
    G -- Yes --> I["Review diff + staging acceptance"]
    I --> J["Merge to main"]
    J --> K["Post-merge CI"]
    K --> L["Tag v* or run release workflow"]
    L --> M["Build installable ZIP + SHA-256"]
    M --> N["GitHub Release asset"]
    N --> O["Manual staging / production install"]
    O --> P["Smoke test"]
    P --> Q["Monitor cron, spend, drafts and analytics"]
```

**Important:** CI now boots WordPress and tests selected database/API/queue paths, but it is not a substitute for a staging rollout. It does not reproduce your live hosting stack, reverse proxy/Cloudflare behavior, real feed providers, real OpenAI account limits, system-cron reliability, concurrent traffic under load, or browser analytics configuration. Complete the staging acceptance checklist and observe a controlled canary before calling a release production-validated.

## Development checks

Run syntax checks and WordPress integration tests locally (after installing the WordPress test library and PHPUnit):

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

The repository's GitHub Actions workflow runs the same check across the PHP matrix. To create a release, push a version tag such as `v0.2.0`; download the ZIP and checksum from the GitHub Release page, verify the checksum, then install the ZIP on staging. Keep secrets out of fixtures and logs, and test schema changes against a copy of production data before release.
