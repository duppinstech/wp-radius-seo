# Radius SEO — website copy

## Hero

**One template. A page for every place you serve.**

Radius SEO is a WordPress plugin that turns a single page blueprint and a place library into local landing pages and service-area hubs. Tokens fill in the city, the phone number, and the rest of your details when each page is published.

[Purchase single site — $9.99 per month](https://buy.stripe.com/6oU7sK8Yt9hag4f0iJ00007)
[Purchase unlimited sites — $99 per month](https://buy.stripe.com/4gM5kCeiN9ha8BN6H700008)

## How an owner sets it up

The setup screenshots are in `screenshots/admin/`. Numbered arrows match `admin-walkthrough.md`.

1. **Templates.** Write one blueprint. Put `{{place_name}}` in the title and body. Screenshot: `screenshots/admin/template-edit.jpg`.
2. **Location library.** Import a CSV of places you serve. Radius does not download the list. A row needs a name, latitude, and longitude. Screenshot: `screenshots/admin/locations.jpg`.
3. **Service areas.** Pick a center place and a radius in miles. Screenshot: `screenshots/admin/settings-areas.jpg`.
4. **Deploy.** Open Deploy and run Deploy & update all places. Each place in range gets one page. Edit the template later and deploy again to update those pages. Screenshot: `screenshots/admin/deploy-run.jpg`.

## How it works

1. **Add places.** Import a CSV or add cities in the location library. Each place needs a name and coordinates.
2. **Write one template.** Put tokens such as `{{city}}` and `{{company-name}}` in the title and body. Optional spintax varies a phrase from page to page.
3. **Deploy.** Radius writes one current page for each place inside your service-area circles. Run deploy again after you edit the template, and the same pages update in place.

## What you get

- Landing pages at the site root and service-area hubs under a prefix you choose
- A place library with CSV import and export
- Site-wide replacers, with a different value inside each service-area circle
- Optional Elementor and Beaver Builder layouts, copied from the template at deploy
- Visit and click counts stored on your own site
- A Help button on every Radius screen, linking to the user guide, and email support from inside wp-admin

## Example

Bee Line Transport Inc. uses Radius SEO on [beelinetransportinc.com](https://beelinetransportinc.com). The same design serves a hub for Coltons Mill and a tire-service page for Abert. Each page carries that place in the heading.

- Hub: https://beelinetransportinc.com/service-area/coltons-mill/
- Landing: https://beelinetransportinc.com/semi-tire-abert/

Use `screenshots/beeline-service-area-coltons-mill.png` beside the hub and `screenshots/beeline-landing-abert.png` beside the landing. Caption: “Published with Radius SEO on Bee Line Transport Inc.”

## Plans

| Plan | Price | Includes |
| --- | --- | --- |
| Single site | $9.99 per month | One site, email support |
| Unlimited sites | $99 per month | Unlimited sites, priority email support |

After checkout, Duppins Technology sends a license key. Paste it under Radius → Settings → License. Cancelling the Stripe subscription does not yet turn a saved key off.

## Short FAQ

**Do I write a page for every city?**
No. You write the template once. Deploy fills a page for each place in range.

**Will a second deploy duplicate pages?**
No. The same template and place update the existing page.

**Where do support requests go?**
An administrator uses Radius → Support. The form emails support@duppinstech.com. Call (240) 397-8221.
