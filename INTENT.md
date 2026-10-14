# Intent

Objective of this repository. Read this before planning a change. Architecture and class maps live in `.claude/memory/project-context.md`. Unfinished work lives in `.claude/memory/progress.md`. Authorship lives in `OWNERSHIP.md`.

## Objective

Radius SEO lets a WordPress site publish many location-specific pages from one blueprint and a place library.

A maintainer writes a template once, imports places, and deploys. Each selected place becomes a landing page or a service-area hub with the right location filled in. The page is correct for that place, and the blueprint stays the thing they edit next time.

## Who this is for

- Operators who need local landing pages and service-area hubs at batch scale, without hand-building a post per city.
- Duppins Technology owns this product. Odell Duppins is the author. WhiteRail is the beta client and does not own the product or the source. See `OWNERSHIP.md`.

## What we intend to do

- Keep one template as the source of a campaign: title, body, tokens, spintax, and Markdown slots.
- Keep places in the `radius_place` library, including CSV import and the term fields the deploy step needs (name, slug, region, coordinates, and the other canonical term meta).
- Deploy and redeploy a template across place ids so each place has one current `radius_landing` or `radius_service_area` post, updated in place on the next run.
- Fill each page through the existing token map and renderer (`{{token}}`, `[token]`, `{a|b|c}`).
- Copy Elementor or Beaver Builder layout from the template only when that builder is turned on in settings.
- Ship plugin fixes to installed sites through a GitHub Release whose ZIP root is `wp-radius-seo/` and contains `radius.php`.

## Done looks like

A change to the blueprint, followed by deploy, produces the selected local pages with the right place, URL, and content. The maintainer does not keep a hand-edited copy of each city. A site already running the plugin can pick up that change from Dashboard → Updates after the release ZIP is published.

## Bounds

This repository is the WordPress plugin and the agent docs that maintain it. Customer copy, logos, and business data stay with the client. Secrets, license keys, and live-site deploys stay off git and off unnamed sites. New stored keys use the `radius_*` / `_radius_*` names in `Radius_Data_Registry`.

If a task does not serve the objective above, stop and ask before writing code.
