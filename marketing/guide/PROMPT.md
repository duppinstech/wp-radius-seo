# Prompt for the website guide

Paste this prompt into the AI that builds pages on duppinstech.com. Attach this whole folder, including `screenshots/`. Do not ask that AI to invent screens, prices, or steps. The articles are already written.

---

You are publishing the Radius SEO user guide on https://duppinstech.com/radius-seo/guide/ for Duppins Technology. Radius SEO is a WordPress plugin. Odell Duppins of Duppins Technology is the author. No customer owns Radius SEO. Do not name a customer, a customer site, or a customer page.

Build a wiki-style guide a site owner can use after they click Help inside wp-admin. The plugin opens one address plus a section id:

https://duppinstech.com/radius-seo/guide/#FRAGMENT

The fragment for each screen is in `fragments.json` and in the HTML comment at the top of each markdown file. The main guide page must contain every section, and each section’s HTML id must be that fragment exactly. A person who lands on `#deploy` must see the Deploy article without hunting. Also give the page a table of contents at the top that links to every fragment, in the order listed in `order.txt`.

You may also create a child page per article. If you do, the main page sections must still exist so the hashes above keep working. Do not change the fragment ids.

Use the markdown files as the words on the page. Keep the steps, button names, prices, and warnings. You may add headings, a sidebar, and “On this screen / Next” links. Do not add features, menus, or buttons that these files do not name.

Place each screenshot beside the steps that refer to it. Articles are in `guide/`. An image written as `../screenshots/admin/dashboard.jpg` is the file `screenshots/admin/dashboard.jpg` in this pack. Red numbers drawn on an admin screenshot match the numbered list under that image in the markdown. Do not crop the numbers off. Admin screenshots show the configuration screens. Do not add a customer’s published pages. Do not present a customer’s phone number or dispatch email as Radius support.

Facts you must not change:

- Support email is support@duppinstech.com. Phone is (240) 397-8221.
- Single site is $9.99 per month: https://buy.stripe.com/6oU7sK8Yt9hag4f0iJ00007
- Unlimited sites is $99 per month: https://buy.stripe.com/4gM5kCeiN9ha8BN6H700008
- After checkout, Duppins Technology sends a license key. The plugin does not see the Stripe payment.
- Cancelling the Stripe subscription does not yet turn a saved key off.
- A signed key can say single site or unlimited. A key already saved on a site keeps working until it is replaced. There is no license server yet, so one key is not refused on a second site.
- Priority email for the unlimited plan is flagged in the Duppins Technology account. The plugin does not store or check that flag.
- Radius does not download a city list. The owner supplies a CSV.
- Deploy updates the existing page for a template and place. It does not add a second copy.
- This guide describes the product. It is not a warranty. License is GPL-2.0-or-later. Copyright Duppins Technology.

The same articles are the GitHub wiki and the PDF inside the plugin. Keep this website page in agreement with them.
