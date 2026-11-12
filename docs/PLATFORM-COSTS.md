# What it costs to run AI Wire

AI Wire is free and open source (**GPLv2 or later**). Running it still uses **your** accounts. OpenAI, X credits, Instagram quota, YouTube quota, and Fal motion are easy to mix up. They are not interchangeable.

| Meter | Who bills it | Required for | What “empty” looks like |
|---|---|---|---|
| OpenAI | OpenAI | Writer, reviewer, social copy, social images | Plugin monthly budget / token ceiling stops new AI calls |
| **X API credits** | X (developer console) | **Automated** tweets and media uploads | Social queue shows **`credits depleted`** on X rows |
| Instagram publishing quota | Meta (no per-post fee) | Automated Instagram posts | Item stays **queued** and is tried again later |
| YouTube Data API quota | Google (no per-upload fee) | Automated YouTube Shorts | Item stays **queued** (`quota` / daily upload limit) and is tried later |
| Fal.ai motion | Fal.ai | Spoken Instagram Reels / YouTube Shorts | Job is **held** when the **$10/month** video budget is exhausted; no slideshow fallback |

The plugin’s own “Today: 8 / 8” article cap is a local control. It does not buy X credits, Instagram quota, YouTube quota, or Fal credits.

## OpenAI (plugin budgets)

You pay OpenAI for:

- Writer + reviewer article text
- Optional reviewer web search
- Social copy (hook, captions, and the spoken Reel script — same Writer model, no second research pass)
- One social graphic per article (GPT Image 2 low by default, about **$0.006** in the plugin’s local estimate)
- GPT-4o Mini TTS when Instagram format is Reel (about **$0.004** per voiceover, counted against the social image budget)
- Fal.ai motion when Instagram format is Reel. **Suggested: Veo 3.1 Lite** (8s silent 720p, about **$0.24** per clip). Separate **$10/month** video budget. Social settings lists Veo Fast, Veo 3.1, Kling 3.0 Standard, and Kling 3.0 Pro with a price info icon and a **Price estimate** page. Story recipe, talking host (Kling Avatar), and avatar-face enhance are off by default.

Social images have a **separate** monthly budget (default **$5**) so they do not silently spend the Writer/Reviewer ceiling. If the graphic already exists, **Post existing graphic** does not generate another image.

Details and tables: [Image generation cost estimate](IMAGE-GENERATION-COST.md). Verify [OpenAI image pricing](https://developers.openai.com/api/docs/pricing) before you raise a budget.

## X — automated posting needs API credits

The plugin cannot post by logging into x.com. It calls the X API (`POST /2/tweets` plus media upload) with the four Keys and tokens values.

That API is billed by **X**, in the [X Developer Console](https://console.x.com). Plans and per-request prices change; this project does **not** promise a monthly X dollar amount.

If the Social queue says **`credits depleted`**:

1. That is X’s credit / spending-limit balance, **not** the plugin image budget.
2. Add credits or raise the spending cap in the X developer portal.
3. Confirm the tweet is not already live.
4. Use **Post existing graphic to X**. Do not generate a new graphic unless you want a different poster.

Manual tweets typed on x.com do **not** spend these API credits. Automated posts from this plugin **do**.

Bearer tokens are not required. Save API Key, API Key Secret, Access Token, and Access Token Secret (OAuth 1.0a, Read and Write).

## Instagram — no Meta per-post fee

Instagram Graph content publishing has **no Meta invoice per API post**. You still need:

- Your own Instagram Professional (Business or Creator) account
- Your own Meta app and publishing permission
- A public HTTPS URL for the generated JPEG

Meta enforces a **rolling 24-hour publishing quota** (documented around 100 API posts per account; a carousel counts as one). The plugin reads `content_publishing_limit` and **defers** when the account is full. That is a quota, not “credits depleted”.

Setup: [Instagram Content Publishing Setup](INSTAGRAM-CONTENT-PUBLISHING-SETUP.md). Official quota notes: [Meta content publishing](https://developers.facebook.com/docs/instagram-platform/content-publishing/).

## YouTube — no per-upload fee

YouTube Data API v3 uploads are **not billed per video**. Google meters **quota**: default **100 `videos.insert` calls per day** (1 unit each, own bucket) plus a 10,000-unit pool for other calls such as `videos.list` (1) and `thumbnails.set` (50). Quotas reset at midnight Pacific Time.

The plugin uploads the spoken Reel MP4 as a **public** Short (not a draft) and sets title, description, tags, category, language, license, privacy, made-for-kids, and synthetic-media disclosure in that API call. It does **not** post still images to YouTube. A `quota` Social queue error means the Google Cloud project hit its daily API quota, not the OpenAI or Fal budget.

Connect with **Sign in with Google** on Social settings (Google Cloud OAuth client ID/secret first). A YouTube API key cannot upload. If the channel default privacy is private, the worker updates the video to public after processing. This repository never ships anyone else’s Google client secret.

Spoken Reels and Shorts also need a Fal.ai key and `ffmpeg` on the WordPress host. Those are not Google charges.

## What this repository never includes

Do not expect, and do not commit:

- Anyone else’s OpenAI key, X tokens, Instagram tokens, Google OAuth client secrets, YouTube refresh tokens, or Fal.ai keys
- A shared Meta app that posts to a third-party account
- Hard-coded newsroom voice, desks, or social handle for one publisher

Each install uses `get_bloginfo('name')` for article bylines, related-story headings, and the social poster brand mark. Connect **your** networks on **Social settings**.
