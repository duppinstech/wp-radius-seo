"""Build the illustrated user manual. The PDF ships. This script does not."""

import json
from pathlib import Path

from PIL import Image as PILImage
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import inch
from reportlab.platypus import Image, PageBreak, Paragraph, SimpleDocTemplate, Spacer

ROOT = Path(__file__).resolve().parents[1]
SHOTS = ROOT / "marketing" / "screenshots" / "admin"
OUT = ROOT / "assets" / "manual" / "radius-seo-user-manual.pdf"


def styles():
    base = getSampleStyleSheet()
    base.add(ParagraphStyle(name="CoverTitle", parent=base["Title"], fontName="Times-Bold", fontSize=26, leading=30, spaceAfter=10))
    base.add(ParagraphStyle(name="Body", parent=base["Normal"], fontName="Times-Roman", fontSize=11, leading=15, spaceAfter=8))
    base.add(ParagraphStyle(name="H", parent=base["Heading1"], fontName="Times-Bold", fontSize=16, leading=20, spaceBefore=12, spaceAfter=8))
    base.add(ParagraphStyle(name="Cap", parent=base["Normal"], fontName="Times-Italic", fontSize=10, leading=13, spaceBefore=2, spaceAfter=4, leftIndent=12))
    return base


def picture(key):
    path = SHOTS / f"{key}.jpg"
    if not path.exists():
        return None
    with PILImage.open(path) as im:
        width, height = im.size
    display_w = 7.15 * inch
    display_h = display_w * height / width
    if display_h > 8.4 * inch:
        display_h = 8.4 * inch
        display_w = display_h * width / height
    return Image(str(path), width=display_w, height=display_h)


def chapter(story, style, manifest, title, paragraphs, images):
    story.append(Paragraph(title, style["H"]))
    for text in paragraphs:
        story.append(Paragraph(text, style["Body"]))
    for key in images:
        img = picture(key)
        if img is None:
            continue
        story.append(Spacer(1, 6))
        story.append(img)
        for item in manifest.get(key, {}).get("callouts", []):
            story.append(Paragraph(f"{item['n']}. {item['text']}", style["Cap"]))
        story.append(Spacer(1, 8))


def build():
    style = styles()
    manifest = json.loads((SHOTS / "manifest.json").read_text(encoding="utf-8"))
    story = [
        Spacer(1, 1.2 * inch),
        Paragraph("Radius SEO", style["CoverTitle"]),
        Paragraph("User guide", style["H"]),
        Paragraph("Duppins Technology", style["Body"]),
        Paragraph("Version 1.7.1", style["Body"]),
        Spacer(1, 0.3 * inch),
        Paragraph(
            "This guide walks the wp-admin screens. Red numbers on each screenshot match the notes under the picture. "
            "The same guide is published at https://duppinstech.com/radius-seo/guide/ . "
            "Inside WordPress, the Help button at the bottom-right of every Radius screen opens that guide.",
            style["Body"],
        ),
        Paragraph(
            "The usual order is: install, save a license, import places, draw a service area, write one template, then deploy. "
            "Deploy updates the existing page for a place. It does not add a second copy.",
            style["Body"],
        ),
        PageBreak(),
    ]
    chapter(
        story, style, manifest,
        "1. Install",
        [
            "In wp-admin open Plugins, Add New, Upload Plugin. Choose the release zip. The zip must contain one folder, and that folder must contain radius.php. Activate Radius SEO.",
            "The plugin then appears under Radius in the left menu. Deactivate is on this Plugins row. It asks whether to keep Radius data or delete it. Cancel, Close, or Escape leaves the plugin active and deletes nothing.",
        ],
        ["plugins"],
    )
    chapter(
        story, style, manifest,
        "2. Obtain a license",
        [
            "Open Radius, Settings, License. Paste the signed key from Duppins Technology into API key and click Save. Check only tells you whether the key is signed. It does not store it. A key already saved on this site keeps working until you replace it or remove it.",
            "Purchase opens Stripe. Single site is $9.99 per month. Unlimited sites is $99 per month. After checkout, paste the key you receive. Cancelling Stripe does not yet turn a saved key off. Until a key is saved, templates, the library, and deploy stay locked.",
        ],
        ["license"],
    )
    chapter(
        story, style, manifest,
        "3. The Help button",
        [
            "Help is a round button at the bottom-right of every Radius screen. It is not a banner across the top. Click it for a short note about the screen you are on, a link to this guide, and Contact support for administrators.",
            "Open the user guide goes to https://duppinstech.com/radius-seo/guide/ . Contact support opens Radius, Support. Close, or the Escape key, puts the panel away and returns focus to Help.",
        ],
        ["help"],
    )
    chapter(
        story, style, manifest,
        "4. Dashboard",
        [
            "Radius, Dashboard is a set of shortcuts. The cards do not change anything by themselves. Use the button on a card to open that screen. The left menu lists the same screens: Analytics, Templates, Landings, Service areas, Location library, Deploy, Import / export, Settings, Logs, and Support.",
        ],
        ["dashboard"],
    )
    chapter(
        story, style, manifest,
        "5. Analytics",
        [
            "Analytics shows visits and outbound clicks on published landings. The counts stay on this site. Read the tables when a chart is hard to see. A failed load says so in text. Analytics is empty until pages have been deployed and visited.",
        ],
        ["analytics"],
    )
    chapter(
        story, style, manifest,
        "6. Templates",
        [
            "A template is the blueprint. One template is usually one service or one page design. Add New Template, then write the page once. Put tokens in the title and body. Deploy copies that blueprint once for each place.",
            "In the editor, the body is the page visitors will see after tokens are filled. {{place_name}} becomes the place name. {{company-name}} and {{phone-number}} come from site replacers. Spintax such as {fast|quick} stores one of those words on that page.",
            "The sidebar holds the landing URL and title patterns. A landing title pattern such as Towing in {{place_name}} is the public title. The template title itself is only the name in wp-admin. Spintax blocks are optional variation for headings and paragraphs. Preview is for people who can edit the template. Visitors do not see the blueprint.",
            "When the wording is right, go to Deploy. Do not write a separate page for every city.",
        ],
        ["templates", "template-edit"],
    )
    chapter(
        story, style, manifest,
        "7. Landings and service-area hubs",
        [
            "Landings are the city pages. Each row is one template plus one place. They live at the site root, for example /tire-repair-springfield/ . A one-off edit on a single landing lasts until the next deploy of that template and place, which rewrites the page from the template. Leave the stored template and place on the page so later deploys find it.",
            "Service areas in the left menu are the hub pages, not the city landings. Their URLs use the prefix from Settings, General, for example /service-area/springfield/ . Create and update hubs from Deploy, on the Service Areas tab, after you choose a service-area template under Settings, General.",
        ],
        ["landings", "service-areas"],
    )
    chapter(
        story, style, manifest,
        "8. Location library",
        [
            "Radius does not download a city list for you. You bring the places. A spreadsheet, a list of cities you serve, or an export from another tool is enough, as long as it is a CSV with a header row.",
            "Required column: name. Deploy skips a place that has no latitude and longitude, so include lat and lng. Optional columns: id, slug, country, region, state, zip. Download CSV on this screen writes a file in that column order, which is the easiest template to fill in.",
            "Example header and one row: name,slug,region,state,zip,lat,lng then Springfield,springfield,Virginia,VA,22150,38.7893,-77.1872 . Upload CSV on Location library. Leave Update existing places off the first time, so rows you already imported are skipped. Turn it on only when you mean to overwrite a match. Only an administrator can upload.",
            "You can also add or edit one place from the table. Edit is where coordinates, region, and ZIP are changed for that place. Export downloads the whole library. The danger zone deletes places. That cannot be undone from this screen.",
        ],
        ["locations"],
    )
    chapter(
        story, style, manifest,
        "9. Service areas, then deploy",
        [
            "Deploy will not run until Settings, Service areas has at least one circle. Each row is an anchor place from the library plus a radius in miles. Save. Radius includes library places that fall inside that circle and skips places with no coordinates. Saving assigns an area code such as sa-… . Site replacers can use that code for a different phone number inside that circle.",
            "Then open Deploy. Pre-flight shows how many places are in the library, how many service areas exist, and how many places are in range. Choose the template on its card and click Deploy and update all places. The button stays off while pre-flight says there is no service area.",
            "If a batch stops, use Continue deployment. If the screen reports a server error, open Logs. Health check reports duplicates and pages that lost their template or place link. It does not publish new pages by itself. Run deploy again after you edit the template. The same place keeps one page.",
        ],
        ["settings-areas", "deploy", "deploy-run"],
    )
    chapter(
        story, style, manifest,
        "10. Import / export",
        [
            "Import / export is for moving pieces in bulk. Spintax and template slots fill variation blocks. The locations tab is the same library as Location library. Use the migration tab only when you are moving an older Magic Page library. Leave migration alone on a new site.",
        ],
        ["import"],
    )
    chapter(
        story, style, manifest,
        "11. Settings, tab by tab",
        [
            "License stores the key. General holds the service-area URL prefix and the deploy batch size. The prefix is the first segment of hub URLs only. Landings stay at the site root. Lower the batch size when a host times out. Each browser request stays within that size. The service-area template on this tab is the blueprint Deploy uses for hubs.",
            "Service areas holds the circles, covered in the deploy chapter. Site replacers are tokens such as company-name and phone-number. The value is used on every deployed page. An area override wins when the place falls inside that circle.",
            "Content and rotation should stay off unless you want spintax rewritten on a timer. Between runs, visitors see the stored page. A health email uses the override address only when you enter one. Otherwise it uses the WordPress admin email.",
            "Database is for leftover Magic Page options after that plugin is off. Do not delete rows you do not recognize.",
            "Integrations turns on Elementor or Beaver Builder only when a template was built in that editor. Save, then deploy. Meta copied on deploy sends matching template fields onto each landing. Tokens in those fields are replaced at deploy.",
        ],
        ["settings-general", "settings-replacers", "settings-content", "settings-database", "settings-integrations"],
    )
    chapter(
        story, style, manifest,
        "12. Logs and support",
        [
            "Open Logs after a deploy or import reports a server error. The processing log is the run. The error log is the failure. Logs stay on this site until a confirmed delete at deactivation.",
            "An administrator opens Radius, Support, and describes the screen, what they were trying to do, and what happened. Email support sends that, plus the name, email, site address, and the WordPress and Radius versions, to support@duppinstech.com. Radius does not keep a copy. Wait a minute before sending another message. If the site cannot send mail, email that address directly.",
        ],
        ["logs", "support"],
    )
    story.append(Paragraph("13. Plans and what this guide is", style["H"]))
    story.append(Paragraph(
        "Single site is $9.99 per month. Unlimited sites is $99 per month. Priority email for the unlimited plan is handled in the Duppins Technology account. The plugin does not store or check that flag.",
        style["Body"],
    ))
    story.append(Paragraph(
        "Copyright Duppins Technology. Radius SEO is licensed under the GNU General Public License, version 2 or later. This guide describes the product. It is not a warranty.",
        style["Body"],
    ))
    OUT.parent.mkdir(parents=True, exist_ok=True)
    doc = SimpleDocTemplate(
        str(OUT),
        pagesize=letter,
        title="Radius SEO user guide",
        author="Duppins Technology",
        leftMargin=0.6 * inch,
        rightMargin=0.6 * inch,
        topMargin=0.6 * inch,
        bottomMargin=0.6 * inch,
    )
    doc.build(story)
    print(OUT, OUT.stat().st_size)


if __name__ == "__main__":
    build()
