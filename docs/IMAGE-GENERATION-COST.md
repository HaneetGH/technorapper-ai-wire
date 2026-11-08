# Image generation cost estimate

**Date:** 8 November 2026  
**Status:** planning and first-implementation estimate for **social** images only.  
**Scope:** OpenAI spend if the Distribution Engine generates an AI image for X/Instagram. This is not an article featured-image cost.

Architecture: [Social Distribution Architecture](SOCIAL-DISTRIBUTION-ARCHITECTURE.md). Verify current [OpenAI image pricing](https://developers.openai.com/api/docs/pricing) before setting a budget. Provider rates change. Plugin USD totals are local estimates, not a provider invoice.

## Hard boundary

AI image generation is a Social Creative Engine step. It is never called by the Writer, Reviewer, article publisher, or WordPress featured-image flow.

| Surface | Image source | OpenAI image spend |
|---|---|---|
| TechnoRapper article featured image | RSS enclosure / media thumbnail / Open Graph / `<img src>` | **$0.** Items with no source photo are skipped. The Writer's `featured_image_alt` is a caption, not a picture. |
| Social post (X, Instagram) | Optional: allowed source image, branded template, or AI illustration | Only the AI path has an image-provider charge. |

A social-generated file may live in WordPress Media under social metadata. It must not become the article's featured image, and a social image failure must not block or change the article.

An earlier planning note treated image generation as a third editorial pass on every article. That framing is withdrawn. Use the social formula below.

## What you already pay (article text)

Default models are GPT-6 Luna (`$0.10` input / `$0.50` output per 1M tokens in `includes/class-ai.php`). One writer + reviewer article is about **$0.003–$0.006**. Optional reviewer web search adds **$0.01**. GPT-6.1 Terra is about 10× the Luna text bill.

| Article volume | Text only (Luna) | + web search |
|---|---|---|
| 1 post | ~$0.004 | ~$0.014 |
| 10 posts/day | ~$1–$2 / month | ~$4 / month |
| 350 posts/day (article daily cap) | ~$37–$63 / month | ~$140 / month |

The article monthly budget defaults to **$5**. Social images must use a **separate** budget so they cannot silently consume Writer/Reviewer headroom.

## Social image spend formula

```
monthly image ≈ published_articles
                × eligible_destinations
                × generation_rate
                × average_cost_per_generation
                × (1 + retry_or_refusal_allowance)
              + copy_generation
              + infrastructure
```

Use measured pilot usage, not a single assumed image-per-article rate.

- **published_articles:** social jobs start only after WordPress `post_status=publish`. Drafts and quarantined items cost $0 for images.
- **eligible_destinations:** X and/or Instagram when that destination is enabled and has credentials.
- **generation_rate:** share of deliveries that actually call the image API. Source reuse and branded templates are **$0** on the image provider. The architecture default is source/template first; AI generation is opt-in.
- **average_cost_per_generation:** model × size × quality (table below), plus prompt tokens.
- **retry/refusal allowance:** budget **10–20%**. News-like prompts with people, logos, or product shots are refused more often than branded illustrations. Do not retry a deterministic refusal forever.
- **copy_generation:** at most one small text pass per article (or one request for both platforms). Luna for a short X + Instagram JSON is about **$0.0003–$0.001**. Do not run a second research/writer pipeline.
- **infrastructure:** WordPress Media, GD title-card rendering, and host cron. Cents next to the API. X API access/usage is **not** in the OpenAI image budget.

## First implementation (social feature branch)

On [PR #39](https://github.com/HaneetGH/technorapper-ai-wire/pull/39), when Social distribution is on and a destination has credentials:

| Setting | Value |
|---|---|
| Model / quality / size | `gpt-image-2`, `low`, **1024×1536** portrait |
| Local reserve | **$0.006** per image (about $0.005 output + prompt allowance) |
| Images per article | **One**, then reused on X and Instagram |
| Social daily cap | **10 unique articles** (not the 350 article cap) |
| Social image budget | Separate, default **$5**/month, local estimate |
| Fallback if budget is 0 or exhausted | Hold/skip the social image **and** that delivery; still does not change the article |
| Title card | Local GD overlay on a 1080×1350 JPEG; no extra image-API charge |

List price for GPT Image 2 **low** at 1024×1536 is about **$0.005**. The $0.006 reserve is the number the plugin should use until actual usage is reconciled.

### Cost at the first-implementation volume

One image per article, generation_rate = 1, 15% retry allowance, copy ≈ $0.0005.

| Volume | Images (before retries) | Image API (with 15% retries) | Copy | Rough social AI total |
|---|---|---|---|---|
| 1 published article | 1 | ~$0.007 | ~$0.0005 | **~$0.01** |
| 10 articles/day (social cap) | 300 / month | **~$2.07** | ~$0.15 | **~$2.20 / month** |
| Both X and Instagram | still 300 images | **~$2.07** | ~$0.15 | **~$2.20 / month** (asset is shared) |

The $5 default social budget covers the 10/day canary with room for retries. It does **not** cover turning the same path on for 350 articles/day.

### If the social cap were later raised (do not assume this)

Same first-impl rate ($0.006) and one shared image per article:

| Unique published articles / day | Image API / month (plus 15%) |
|---|---|
| 10 (current social cap) | ~$2 |
| 50 | ~$10 |
| 350 (article cap; social must keep its own cap) | **~$72** |

Raising social volume is a separate decision from the article daily cap.

## Quality / size ladder (if the default changes)

Prices are one GPT Image 2 output. Social first-impl uses **low 1024×1536**.

| Quality | 1024×1536 portrait | 1024×1024 square |
|---|---|---|
| **Low (current social default)** | **~$0.005** | ~$0.006 |
| Medium | ~$0.042 | ~$0.053 |
| High | ~$0.165 | ~$0.211 |

At 10 articles/day, medium would be about **$15/month** before retries; high about **$57/month**. Do not switch quality without raising the social image budget.

A distinct AI image **per destination** (instead of one shared file) multiplies the image line by the number of enabled platforms.

## Cheaper paths (architecture, not all in v1)

1. **Leave Social distribution off.** $0 social image spend. Articles still publish.
2. **Source image or branded template.** $0 image-provider charge; copy generation only.
3. **Keep GPT Image 2 low portrait** when AI is on. First-impl default.
4. **Keep the separate $5 social image ceiling** until a canary measures refusals and invoice drift.
5. **Illustrative graphics, not fake news photographs.** Fewer refusals; matches the editorial rule that social AI must not look like documentary coverage of real people or events.

## Other costs (not OpenAI images)

- **WordPress Media:** one 4:5 JPEG per social article. At 10/day, on the order of 100 MB/month, not gigabytes.
- **X API:** media upload and post creation are billed by X. Check the developer console. Not in `social_image_budget_usd`.
- **Instagram:** no per-post OpenAI-style line in this estimate; Meta app access, quotas, and a publicly fetchable media URL still apply.
- **PHP GD:** required for the title card; no API charge.

## Recommendation

Budget social AI images as **~$0.006 extra per published article that actually generates**, not per TechnoRapper page view and not per article featured image.

At the first-implementation cap of **10 social articles/day**, plan about **$2–$3/month** OpenAI image spend plus a small copy pass, under a **separate $5** social image budget. Leave the article text budget for Writer/Reviewer. Do not enable AI images for the 350/day article cap.

If generation is later made optional (source/template first), the image line falls with `generation_rate`. Measure that rate in the canary instead of assuming every social post pays for a new image.
