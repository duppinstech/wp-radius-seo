"""Capture Radius admin screens and draw numbered arrows. Not shipped."""

import json
import os
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "marketing" / "screenshots" / "admin"
BASE = os.environ.get("RADIUS_LOCAL_URL", "http://127.0.0.1:8088")
USER = os.environ.get("RADIUS_LOCAL_USER", "radius")
PASSWORD = os.environ["RADIUS_LOCAL_PASSWORD"]

HIDE = """
.update-nag, .notice-warning { display: none !important; }
.radius-help-launcher { display: none !important; }
"""


def boxes_for(page, selectors):
    found = []
    for spec in selectors:
        loc = page.locator(spec["selector"]).first
        try:
            if loc.count() == 0:
                continue
            loc.scroll_into_view_if_needed(timeout=3000)
            box = loc.bounding_box()
        except Exception:
            continue
        if not box or box["width"] < 2:
            continue
        found.append({"n": len(found) + 1, "text": spec["text"], "box": box})
    return found


def annotate(png_path, callouts, dest):
    image = Image.open(png_path).convert("RGB")
    draw = ImageDraw.Draw(image)
    font = ImageFont.truetype(r"C:\Windows\Fonts\arialbd.ttf", 20)
    for item in callouts:
        box = item["box"]
        n = item["n"]
        target_x = box["x"] + min(24, box["width"] / 2)
        target_y = box["y"] + box["height"] / 2
        if box["width"] < 220:
            badge_x = min(image.width - 40, box["x"] + box["width"] + 8)
            badge_y = max(8, box["y"] - 4)
        else:
            badge_x = max(8, box["x"] - 8)
            badge_y = max(8, box["y"] - 34)
        radius = 15
        draw.line((badge_x + radius, badge_y + radius, target_x, target_y), fill="#d63638", width=4)
        draw.ellipse(
            (badge_x, badge_y, badge_x + radius * 2, badge_y + radius * 2),
            fill="#d63638",
            outline="#ffffff",
            width=2,
        )
        label = str(n)
        draw.text((badge_x + (6 if n < 10 else 2), badge_y + 3), label, fill="#ffffff", font=font)
    image.save(dest, "JPEG", quality=78, optimize=True)


def shot(page, name, selectors):
    page.add_style_tag(content=HIDE)
    page.evaluate("window.scrollTo(0, 0)")
    page.wait_for_timeout(250)
    for spec in selectors:
        if spec.get("scroll"):
            loc = page.locator(spec["selector"]).first
            if loc.count():
                loc.scroll_into_view_if_needed(timeout=4000)
            break
    page.wait_for_timeout(250)
    callouts = []
    for spec in selectors:
        loc = page.locator(spec["selector"]).first
        try:
            if loc.count() == 0:
                continue
            box = loc.bounding_box()
        except Exception:
            continue
        if not box or box["width"] < 2 or box["y"] < 0 or box["y"] > 960:
            continue
        callouts.append({"n": len(callouts) + 1, "text": spec["text"], "box": box})
    raw = OUT / f"_{name}.png"
    page.screenshot(path=str(raw), full_page=False)
    annotate(raw, callouts, OUT / f"{name}.jpg")
    raw.unlink(missing_ok=True)
    return [{"n": item["n"], "text": item["text"]} for item in callouts]


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    only = {part for part in os.environ.get("RADIUS_CAPTURE_ONLY", "").split(",") if part}
    manifest = {}
    if only and (OUT / "manifest.json").exists():
        manifest = json.loads((OUT / "manifest.json").read_text(encoding="utf-8"))
    with sync_playwright() as p:
        browser = p.chromium.launch()
        context = browser.new_context(viewport={"width": 1440, "height": 1000})
        page = context.new_page()
        page.goto(f"{BASE}/wp-login.php", wait_until="domcontentloaded")
        page.fill("#user_login", USER)
        page.fill("#user_pass", PASSWORD)
        page.click("#wp-submit")
        page.wait_for_url("**/wp-admin/**", timeout=20000)

        visits = [
            (
                "plugins",
                f"{BASE}/wp-admin/plugins.php",
                [
                    {"selector": "tr[data-slug='wp-radius-seo'] .plugin-title strong, tr.active th.plugin-title strong", "text": "Radius SEO in the plugin list. This is the installed plugin."},
                    {"selector": "tr[data-slug='wp-radius-seo'] .deactivate a, tr.active .deactivate a", "text": "Deactivate opens the keep-or-delete choice. Cancel leaves the plugin on."},
                ],
            ),
            (
                "license",
                f"{BASE}/wp-admin/admin.php?page=radius-settings&tab=license",
                [
                    {"selector": "#radius-api-key, input[name='radius_api_key']", "text": "Paste the signed license here, then Save. Check does not store the key."},
                    {"selector": "a[href*='buy.stripe.com']", "text": "Purchase opens Stripe. After checkout, paste the key Duppins Technology sends."},
                ],
            ),
            (
                "license-plans",
                f"{BASE}/wp-admin/admin.php?page=radius-settings&tab=license",
                [
                    {"selector": "a[href*='buy.stripe.com']", "text": "Purchase opens Stripe in a new tab. Single site and Unlimited sites are both listed. After checkout, paste the key into the API key field.", "scroll": True},
                ],
            ),
            (
                "dashboard",
                f"{BASE}/wp-admin/admin.php?page=radius",
                [
                    {"selector": "a.button:has-text('Manage templates')", "text": "Templates is where you write the blueprint."},
                    {"selector": "a.button:has-text('Open library')", "text": "Location library is where places are imported."},
                    {"selector": "a.button:has-text('Open deploy')", "text": "Deploy publishes a page for each place after the template is ready."},
                ],
            ),
            (
                "analytics",
                f"{BASE}/wp-admin/admin.php?page=radius-analytics",
                [
                    {"selector": "h1", "text": "Analytics counts visits and outbound clicks on published landings. The numbers stay on this site."},
                ],
            ),
            (
                "templates",
                f"{BASE}/wp-admin/edit.php?post_type=radius_template",
                [
                    {"selector": "a.page-title-action", "text": "Add New Template starts a blueprint. One template is usually one service."},
                    {"selector": "#the-list tr", "text": "Each row is a blueprint. Open it to put tokens in the title and body, then deploy it."},
                ],
            ),
            (
                "landings",
                f"{BASE}/wp-admin/edit.php?post_type=radius_landing",
                [
                    {"selector": "h1", "text": "Each landing is one deployed page for one template and one place."},
                    {"selector": "#the-list tr", "text": "Deploy again to update these pages. It does not add a second copy of the same place."},
                ],
            ),
            (
                "service-areas",
                f"{BASE}/wp-admin/edit.php?post_type=radius_service_area",
                [
                    {"selector": "h1", "text": "Service areas are hub pages. Their URLs use the prefix from Settings, General."},
                ],
            ),
            (
                "locations",
                f"{BASE}/wp-admin/admin.php?page=radius-locations",
                [
                    {"selector": "#radius-csv-library", "text": "Upload a CSV with columns name, lat, and lng. A name column is required."},
                    {"selector": "input[name='radius_csv_update_existing']", "text": "Leave this off to skip places you already imported. Turn it on to overwrite matches."},
                    {"selector": "input#submit[value='Download CSV'], #submit", "text": "Download CSV shows the column order: id, name, slug, country, region, state, zip, lat, lng."},
                ],
            ),
            (
                "deploy",
                f"{BASE}/wp-admin/admin.php?page=radius-deploy",
                [
                    {"selector": ".radius-preflight, .radius-deploy-preflight", "text": "Pre-flight must show at least one service area before Deploy will run."},
                    {"selector": "a:text-is('Service areas & deploy batch size')", "text": "Open Settings, Service areas, and add a center place plus a radius in miles."},
                ],
            ),
            (
                "deploy-run",
                f"{BASE}/wp-admin/admin.php?page=radius-deploy",
                [
                    {"selector": "input[value='Deploy & update all places']", "text": "After the template is ready, Deploy and update all places writes one current landing per place in range.", "scroll": True},
                    {"selector": "a:text-is('Edit template')", "text": "Edit template returns to the blueprint if the pages need different wording."},
                ],
            ),
            (
                "template-edit",
                f"{BASE}/wp-admin/post.php?post=9&action=edit",
                [
                    {"selector": "#title, .editor-post-title__input, h1.wp-block-post-title", "text": "The template title is the blueprint name in wp-admin. Landing titles can use a separate pattern with tokens."},
                    {"selector": "#radius_landing_title_pattern, input[name='radius_landing_title_pattern']", "text": "Landing title pattern. Example: Towing in {{place_name}}. Deploy fills the place name.", "scroll": True},
                ],
            ),
            (
                "import",
                f"{BASE}/wp-admin/admin.php?page=radius-import",
                [
                    {"selector": "h1, h2", "text": "Import / export holds spintax, template slots, place CSV, and the Magic Page migration. Use migration only when moving an older library."},
                ],
            ),
            (
                "settings-general",
                f"{BASE}/wp-admin/admin.php?page=radius-settings&tab=general",
                [
                    {"selector": "a.nav-tab", "text": "Each settings job has its own tab. License, General, Service areas, Site replacers, Content and rotation, Database, Integrations."},
                    {"selector": "#service_area_url_slug, input[name*='service_area_url']", "text": "This prefix is only for hub URLs. Landings stay at the site root."},
                    {"selector": "input[name*='batch']", "text": "Lower the deploy batch size when a host times out."},
                ],
            ),
            (
                "settings-areas",
                f"{BASE}/wp-admin/admin.php?page=radius-settings&tab=areas",
                [
                    {"selector": "input[placeholder*='search places']", "text": "Anchor place is the center of the circle. Pick it from the location library."},
                    {"selector": "input[name*='miles'], td input[type='number'], .radius-areas input[type='text'] + input", "text": "Radius in miles. Deploy includes library places inside this circle."},
                    {"selector": "button:text-is('Add service area row')", "text": "Add another circle when you serve more than one region. Save to assign area codes."},
                ],
            ),
            (
                "settings-replacers",
                f"{BASE}/wp-admin/admin.php?page=radius-settings&tab=site_replacements",
                [
                    {"selector": "h1, .nav-tab-active", "text": "A replacer fills a token such as {{company-name}} or {{phone-number}} on every deployed page."},
                ],
            ),
            (
                "settings-content",
                f"{BASE}/wp-admin/admin.php?page=radius-settings&tab=content",
                [
                    {"selector": "h1, .nav-tab-active", "text": "Leave scheduled rotation off unless you want spintax rewritten on a timer."},
                ],
            ),
            (
                "settings-database",
                f"{BASE}/wp-admin/admin.php?page=radius-settings&tab=database",
                [
                    {"selector": "h1, .nav-tab-active", "text": "Database is for leftover Magic Page options. Leave it alone on a new site."},
                ],
            ),
            (
                "settings-integrations",
                f"{BASE}/wp-admin/admin.php?page=radius-settings&tab=integrations",
                [
                    {"selector": "input[name='enable_elementor']", "text": "Turn on Elementor only when a template was built in Elementor. Save, then deploy."},
                    {"selector": "input[name='enable_beaver_builder']", "text": "Turn on Beaver Builder only when a template was built in Beaver Builder. Save, then deploy."},
                ],
            ),
            (
                "logs",
                f"{BASE}/wp-admin/admin.php?page=radius-logs",
                [
                    {"selector": "h1", "text": "Open Logs when deploy or import reports a server error. The processing log is the run. The error log is the failure."},
                ],
            ),
            (
                "support",
                f"{BASE}/wp-admin/admin.php?page=radius-support",
                [
                    {"selector": "#radius-support-trying", "text": "Describe what you were trying to do."},
                    {"selector": "#radius-support-happened", "text": "Describe what happened. Email support sends this to support@duppinstech.com and does not store a copy."},
                ],
            ),
        ]

        for name, url, selectors in visits:
            if only and name not in only:
                continue
            page.goto(url, wait_until="domcontentloaded")
            page.wait_for_timeout(600)
            if name == "template-edit":
                for _ in range(3):
                    page.keyboard.press("Escape")
                    page.wait_for_timeout(200)
            manifest[name] = {"file": f"{name}.jpg", "callouts": shot(page, name, selectors)}
            print(name, len(manifest[name]["callouts"]))

        page.goto(f"{BASE}/wp-admin/admin.php?page=radius", wait_until="domcontentloaded")
        page.wait_for_timeout(400)
        page.click("#radius-help-button")
        page.wait_for_timeout(300)
        raw = OUT / "_help.jpg"
        page.screenshot(path=str(OUT / "_help.png"))
        Image.open(OUT / "_help.png").convert("RGB").save(OUT / "help.jpg", "JPEG", quality=78, optimize=True)
        (OUT / "_help.png").unlink(missing_ok=True)
        manifest["help"] = {
            "file": "help.jpg",
            "callouts": [
                {"n": 1, "text": "Help sits at the bottom-right of every Radius screen. It is not a banner at the top."},
                {"n": 2, "text": "Open the user guide goes to the guide on duppinstech.com."},
                {"n": 3, "text": "Contact support opens Radius, Support, for administrators."},
            ],
        }
        browser.close()

    (OUT / "manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    print("wrote", OUT / "manifest.json")


if __name__ == "__main__":
    main()
