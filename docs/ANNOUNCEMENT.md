# Public announcement copy

Ready-to-paste posts. They describe the plugin as a generic WordPress tool. They do not include tokens, private dashboards, internal queue IDs, or one newsroom’s playbook.

Repository: https://github.com/HaneetGH/technorapper-ai-wire

## LinkedIn

We are open-sourcing AI Wire.

It is a WordPress plugin any publisher can run: your RSS feeds become reviewed articles on your site, then can become branded posts on your X and Instagram.

What it does

You point it at feeds you choose. A writer pass turns each new item into an original, source-attributed article. A separate reviewer checks claims, usefulness, and policy before anything goes live. Passing items can publish automatically, or wait as drafts. After the WordPress article is live, an optional social worker can create one branded graphic and post it to the networks you turned on.

Pipeline

Feeds → dedupe queue → Writer → independent Reviewer → WordPress draft or publish → (only if published) social copy + graphic → X and/or Instagram, each on its own queue row.

Rejected items stay quarantined. A failure on X does not retry or block Instagram, and the other way around. The social image is never the article featured image.

Architecture

Two layers, not one giant agent.

Editorial owns the article: ingestion, writer, reviewer, WordPress publish, SEO fields, daily and spend caps.

Distribution is downstream. It starts only after WordPress confirms publish. It repackages approved fields into platform copy, renders a site-branded poster from your WordPress site name, and talks to X and Instagram through separate adapters. Site context (name, tagline, desks, recent stories) is learned from the install, so the pipeline is not hard-wired to one brand.

You bring the WordPress site, the feeds, and the API keys. Social stays off until you enable each network.

Code and walkthrough: https://github.com/HaneetGH/technorapper-ai-wire

## Shorter GitHub / X variant

Open-sourcing AI Wire: RSS → writer → independent reviewer → WordPress article → optional branded graphic on your X and Instagram.

Editorial and social are separate layers. Social only runs after publish. One queue row per network, so one platform can fail without touching the other.

https://github.com/HaneetGH/technorapper-ai-wire
