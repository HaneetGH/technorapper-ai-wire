# Public announcement copy

Ready-to-paste posts. They describe the plugin as a generic WordPress tool. They do not include tokens, private dashboards, internal queue IDs, or one newsroom’s playbook.

Repository: https://github.com/HaneetGH/technorapper-ai-wire  
License: GPLv2 or later

## LinkedIn

We are open-sourcing AI Wire, a WordPress plugin any publisher can run.

It takes RSS or Atom feeds you configure, writes an attributed draft, runs a separate AI review, and can publish to WordPress. After an article is live, it can optionally create one branded graphic and post it to X and/or Instagram — using **your** site name, **your** feeds, and **your** social accounts.

This is not a closed pipeline for one brand. Install it on any WordPress 6.2+ site. Point it at your OpenAI key. Connect your X developer app and/or your Instagram Professional account. Social distribution stays off until you turn each network on.

A few cost facts we want in the open, because they get mixed up:

- OpenAI bills writer, reviewer, and social images. You set monthly budgets in the plugin.
- Automated X posts go through the X API. That spends **X developer credits**. If the queue says “credits depleted”, top up in the X Developer Console. That is not the image budget, and it is not Instagram.
- Instagram Graph publishing has **no Meta per-post fee**. Meta enforces a rolling 24-hour quota. When the quota is full, the plugin waits and retries.

Credentials never belong in the git repo. Each operator stores their own keys on their own WordPress host.

Code, docs, and installable releases: https://github.com/HaneetGH/technorapper-ai-wire

If you run a newsroom, a niche blog, or a product site on WordPress and you want a reviewed RSS-to-article pipeline with optional social posting, you can use this.

## Shorter GitHub / X variant

Open-sourcing AI Wire: a GPLv2 WordPress plugin that turns your RSS feeds into reviewed articles, then can post a branded graphic to your X and Instagram accounts.

Bring your own OpenAI key and social apps. Automated X posts need X API credits. Instagram API posting has no Meta per-post fee (rolling quota only).

https://github.com/HaneetGH/technorapper-ai-wire
