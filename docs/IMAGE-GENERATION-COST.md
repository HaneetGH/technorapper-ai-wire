# Image generation cost estimate

**Date:** 6 November 2026  
**Status:** planning estimate only. The plugin does not generate images.  
**Scope:** extra OpenAI spend if AI Wire added a third pass that creates a new featured image for each published post.

Verify current [OpenAI image pricing](https://developers.openai.com/api/docs/pricing) before setting a budget. Provider rates change. The plugin's own USD totals are local estimates from `includes/class-ai.php` (`model_rates()`), not a provider invoice.

## Current behaviour (no image API spend)

Featured images are the RSS item's own photo URL (enclosure, media thumbnail, Open Graph image, or `<img src>`). The plugin does not copy that file into the WordPress media library. Items with no image URL are skipped. The writer already returns `featured_image_alt` as a caption; it does not create a picture.

A generated-image layer would run only after writer and reviewer both pass, then sideload the file into the media library. OpenAI image URLs expire, so hotlinking a generated file is not viable.

## What you already pay (text)

Default models are GPT-6 Luna (`$0.10` input / `$0.50` output per 1M tokens in the plugin rate table). One writer + reviewer article is about **$0.003–$0.006**. Optional reviewer web search adds **$0.01** (`SEARCH_CALL_USD`). GPT-6.1 Terra is about 10× the Luna text bill.

| Volume | Text only (Luna) | + web search |
|---|---|---|
| 1 post | ~$0.004 | ~$0.014 |
| 10 posts/day | ~$1–$2 / month | ~$4 / month |
| 350 posts/day (plugin daily cap) | ~$37–$63 / month | ~$140 / month |

The dashboard monthly budget defaults to **$5** and can be raised to **$10,000**. $5 covers a small text canary; it does not cover image generation at volume.

## Extra cost of one new image per post

Prices below are one **1536×1024 landscape** (typical featured / Open Graph size) on the current OpenAI Images API. Prompt tokens add a few tenths of a cent. Budget **10–20% extra** for refusals and retries: news-like prompts with people, logos, or product shots are refused more often than branded illustrations.

| Setup | Per image | 10 posts/day | 350 posts/day |
|---|---|---|---|
| **gpt-image-1-mini, medium** (budget editorial) | **~$0.015** | ~$0.15 / day · **~$4.50 / month** | ~$5.25 / day · **~$158 / month** |
| gpt-image-1-mini, high | ~$0.052 | ~$0.52 / day · ~$16 / month | ~$18 / day · ~$546 / month |
| **gpt-image-2, low** (cheap flagship) | **~$0.005** | ~$0.05 / day · **~$1.50 / month** | ~$1.75 / day · **~$53 / month** |
| **gpt-image-2, medium** (homepage quality) | **~$0.042** | ~$0.42 / day · **~$13 / month** | ~$15 / day · **~$441 / month** |
| gpt-image-2, high | ~$0.165 | ~$1.65 / day · ~$50 / month | ~$58 / day · ~$1,733 / month |

Square 1024×1024 is similar; gpt-image-2 high square is about **$0.211**. Cheaper third-party hosts (FLUX and similar) can be ~$0.003–$0.03 per image, but that is a second vendor. The plugin today has a single OpenAI key.

### Combined bill at 350 posts/day

| Stack | Rough monthly total |
|---|---|
| Luna text only (today) | ~$40–$60 |
| Luna + mini medium images | **~$200** |
| Luna + gpt-image-2 medium images | **~$480** |
| Luna + gpt-image-2 high images | **~$1,800** |

Image generation dominates the text pipeline. Do not turn it on for the full daily cap until the monthly budget is raised to at least **$200** (mini/medium) or **$500** (gpt-image-2 medium).

## Other costs (small next to the API)

- **Sideload / disk:** generated JPEGs must be stored in WordPress media. ~400 KB × 350 posts/day ≈ **4 GB/month**. Hosting/CDN is usually cents compared with the API.
- **Prompt writing:** reuse title, lede, and `featured_image_alt`, or add a short Luna prompt step (~$0.0002). Negligible.
- **Failed items that currently skip:** generating a picture for RSS items with no photo also spends writer + reviewer on items that today never reach a post. That extra text is still small versus the image call.

## Cheaper rollout options

1. **Generate only when the feed has no photo.** Keeps current RSS images at $0. Pays only to un-skip items. Cost follows how many feeds lack enclosures, not the 350 daily cap.
2. **Keep RSS photos, generate never.** Current behaviour. $0 image spend.
3. **Stay on gpt-image-1-mini medium.** Best fit if the site keeps one OpenAI key and wants original art without the flagship bill.
4. **Prefer branded illustration prompts** over fake news photographs. Fewer refusals, safer for a news desk, cheaper to get a usable first pass on mini/low.

## Recommendation

Treat a generated featured image as **~$0.015 extra per published post** (mini/medium) or **~$0.04** (gpt-image-2 medium). The lowest-cost useful layer is “generate only when the RSS item has no image,” with a raised monthly budget and a fallback to skip (or keep the RSS photo) when the image API refuses.

This document is not a commitment to ship that layer. Implementation would also need a queue stage, media sideload, budget accounting for image calls, and editorial policy for generated stills.
