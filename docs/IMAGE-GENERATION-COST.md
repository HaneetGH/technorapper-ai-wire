# Image generation cost estimate

**Date:** 6 November 2026  
**Status:** implementation estimate for the opt-in social distribution feature. The article pipeline still does not generate or replace article featured images.

**Scope:** extra OpenAI spend for one generated social image per published AI Wire article, reused on connected X and Instagram accounts.

Verify current [OpenAI image pricing](https://developers.openai.com/api/docs/pricing) before setting a budget. Provider rates change. The plugin's own USD totals are local estimates from `includes/class-ai.php` (`model_rates()`), not a provider invoice.

## Article images versus social images

Article featured images remain the RSS item's own photo URL (enclosure, media thumbnail, Open Graph image, or `<img src>`). The article pipeline does not copy that file into the WordPress media library; items with no image URL are skipped. The Writer's `featured_image_alt` remains a caption, not a picture.

When Social distribution is enabled and a configured account is connected, a copy pass writes a hook, on-image headline, captions and a visual brief from the published article. Then the image layer requests a portrait illustration, overlays that post info as a 4:5 card branded with **this WordPress site’s name**, stores it as a social asset (never the article featured image), and reuses it for configured platforms. The returned image payload is saved locally; the plugin does not depend on a temporary remote image URL. Copy generation is a small Luna JSON call (~$0.001) and is billed against the Writer/Reviewer budget, not the social image budget.

## What you already pay (text)

Default models are GPT-6 Luna (`$0.10` input / `$0.50` output per 1M tokens in the plugin rate table). One writer + reviewer article is about **$0.003–$0.006**. Optional reviewer web search adds **$0.01** (`SEARCH_CALL_USD`). GPT-6.1 Terra is about 10× the Luna text bill.

| Volume | Text only (Luna) | + web search |
|---|---|---|
| 1 post | ~$0.004 | ~$0.014 |
| 10 posts/day | ~$1–$2 / month | ~$4 / month |
| 350 posts/day (plugin daily cap) | ~$37–$63 / month | ~$140 / month |

The article text budget defaults to **$5** and can be raised to **$10,000**. Social images have a separate **$5** default budget so image calls do not silently consume the Writer/Reviewer ceiling.

## Extra cost of one new image per post

Prices below are one **1536×1024 landscape** (typical featured / Open Graph size) on the current OpenAI Images API. Prompt tokens add a few tenths of a cent. Budget **10–20% extra** for refusals and retries: news-like prompts with people, logos, or product shots are refused more often than branded illustrations.

| Setup | Per image | 10 posts/day | 350 posts/day |
|---|---|---|---|
| gpt-image-1-mini, medium (deprecated; historical price) | ~$0.015 | ~$0.15 / day · ~$4.50 / month | ~$5.25 / day · ~$158 / month |
| gpt-image-1-mini, high (deprecated; historical price) | ~$0.052 | ~$0.52 / day · ~$16 / month | ~$18 / day · ~$546 / month |
| **gpt-image-2, low** (cheap flagship) | **~$0.005** | ~$0.05 / day · **~$1.50 / month** | ~$1.75 / day · **~$53 / month** |
| **gpt-image-2, medium** (homepage quality) | **~$0.042** | ~$0.42 / day · **~$13 / month** | ~$15 / day · **~$441 / month** |
| gpt-image-2, high | ~$0.165 | ~$1.65 / day · ~$50 / month | ~$58 / day · ~$1,733 / month |

Square 1024×1024 is similar; gpt-image-2 high square is about **$0.211**. Cheaper third-party hosts (FLUX and similar) can be ~$0.003–$0.03 per image, but that is a second vendor. The plugin today has a single OpenAI key.

### Reference combined bill at 350 posts/day

This is a comparison for hypothetical higher-volume use; the social distribution setting currently caps publishing at 10 articles/day.

| Stack | Rough monthly total |
|---|---|
| Luna text only (today) | ~$40–$60 |
| Luna + mini medium images | **~$200** |
| Luna + gpt-image-2 medium images | **~$480** |
| Luna + gpt-image-2 high images | **~$1,800** |

Image generation dominates the text pipeline at higher volumes. For the configured 10-article daily cap, the low-quality image estimate is about **$1.50/month** before retries and output variation.

## Other costs (small next to the API)

- **Sideload / disk:** generated JPEGs must be stored in WordPress media. ~400 KB × 350 posts/day ≈ **4 GB/month**. Hosting/CDN is usually cents compared with the API.
- **Prompt writing:** the implementation reuses the article title and excerpt (or a short body excerpt) for the image prompt; prompt tokens are included in the local per-image reserve. Negligible beside image output.
- **Failed items:** only successfully published AI Wire articles enter the social queue; image API calls are therefore limited to eligible published articles.
- **X API credits:** automated tweets and media uploads are billed by X, not OpenAI. A queue error of `credits depleted` means the X developer balance (or spending limit) is empty. That is not this image budget. Check the X Developer Console. Manual tweets on x.com do not spend API credits.
- **Instagram:** Meta does not charge a per-post API fee. The plugin honors Meta’s rolling 24-hour publishing quota and defers when it is full. See [What it costs to run](PLATFORM-COSTS.md).

## Cheaper rollout options

1. **Leave Social distribution off.** Current cost is $0 until the switch is enabled.
2. **Use GPT Image 2 low for social cards.** The implementation creates one original editorial illustration per article, overlays a branded title card, then reuses it across destinations.
3. **Keep an image budget ceiling.** The dashboard defaults to $5/month and stops requesting new images when the local estimate reaches the cap.
4. **Prefer editorial illustrations** over fake news photographs. This reduces the chance of implying that a generated scene is a real event photograph.

## Recommendation

For the default GPT Image 2 low portrait output, the image output is about **$0.005**; the implementation reserves **$0.006 per image** to include a small prompt allowance. The dashboard records a local estimate and stops at the separate image budget; check the OpenAI usage dashboard for the invoice amount. At 10 articles/day, this reserve is about **$1.80/month** before retries and output variation. Image and ambiguous platform failures go to review; Instagram quota-full items remain queued for a later check. No platform post is sent without its branded image.

The image budget is a local estimate and not a provider-side hard limit. Check OpenAI usage, X credits, Instagram quota, and the target WordPress host during the staging canary before raising either the daily article cap or image budget.
