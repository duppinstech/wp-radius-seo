"""Build assets/manual/radius-seo-user-manual.pdf. Not shipped; the PDF is."""

from pathlib import Path

from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import inch
from reportlab.platypus import (
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
)

OUT = Path(__file__).resolve().parents[1] / "assets" / "manual" / "radius-seo-user-manual.pdf"


def styles():
    base = getSampleStyleSheet()
    base.add(
        ParagraphStyle(
            name="CoverTitle",
            parent=base["Title"],
            fontName="Times-Bold",
            fontSize=28,
            leading=32,
            spaceAfter=12,
        )
    )
    base.add(
        ParagraphStyle(
            name="Body",
            parent=base["Normal"],
            fontName="Times-Roman",
            fontSize=11,
            leading=15,
            spaceAfter=8,
        )
    )
    base.add(
        ParagraphStyle(
            name="H",
            parent=base["Heading1"],
            fontName="Times-Bold",
            fontSize=16,
            leading=20,
            spaceBefore=14,
            spaceAfter=8,
        )
    )
    base.add(
        ParagraphStyle(
            name="H2",
            parent=base["Heading2"],
            fontName="Times-Bold",
            fontSize=13,
            leading=16,
            spaceBefore=10,
            spaceAfter=6,
        )
    )
    return base


def p(text, style):
    return Paragraph(text, style)


def build():
    s = styles()
    story = [
        Spacer(1, 1.4 * inch),
        p("Radius SEO", s["CoverTitle"]),
        p("User manual", s["H"]),
        p("Duppins Technology", s["Body"]),
        p("Version 1.7.0", s["Body"]),
        Spacer(1, 0.4 * inch),
        p(
            "This manual explains how to build local landing pages from one template and a place library. "
            "The same file is linked from every Radius screen in wp-admin.",
            s["Body"],
        ),
        PageBreak(),
        p("1. What the plugin does", s["H"]),
        p(
            "Radius SEO keeps a blueprint (a template) and a library of places. Deploy writes one page per place "
            "that falls inside a service-area circle. Tokens such as {{city}} and {{company-name}} are replaced "
            "when the page is written. Spintax such as {fast|quick|reliable} picks one phrase and stores it. "
            "Visitors then see that stored page until you deploy again or turn on scheduled rotation.",
            s["Body"],
        ),
        p(
            "Landing pages live at the site root, for example /tire-repair-springfield/. Service-area hubs use the prefix "
            "from Settings, for example /service-area/springfield/. Deploy updates an existing page for the same "
            "template and place. It does not add a second copy.",
            s["Body"],
        ),
        p("2. First-time setup", s["H"]),
        p("License", s["H2"]),
        p(
            "Open Radius, then Settings, then License. Paste a signed license from Duppins Technology and click Save. "
            "Check only reports whether the key is signed. It does not store the key. A key already saved on the site "
            "keeps working until you replace it or remove it. Purchase links open Stripe. After checkout, paste the "
            "key you receive. Cancelling Stripe does not yet turn a saved key off.",
            s["Body"],
        ),
        p("Places and service areas", s["H2"]),
        p(
            "Open Location library and add places, or import a CSV. Each place needs a name plus latitude and longitude. "
            "Rows without coordinates are skipped at deploy. Rows already in the library are skipped unless Update existing "
            "places is checked. Only an administrator can upload the CSV.",
            s["Body"],
        ),
        p(
            "Open Settings, then Service areas. Add a center place and a radius in miles, then save. Deploy includes "
            "library places inside those circles.",
            s["Body"],
        ),
        p("Template and deploy", s["H2"]),
        p(
            "Open Templates and add a blueprint. Use tokens in the title and body. Open Deploy, choose that template, "
            "and run Deploy and update all places. If a batch stops, use Continue deployment. If the screen reports a "
            "server error, open Logs.",
            s["Body"],
        ),
        p(
            "If the template was built in Elementor or Beaver Builder, open Settings, then Integrations, turn that "
            "editor on, save, and deploy again.",
            s["Body"],
        ),
        p("3. Screens", s["H"]),
        p("Dashboard", s["H2"]),
        p(
            "Shortcuts into the rest of Radius. The cards do not change pages by themselves. The usual order is License, "
            "Location library, a template, then Deploy.",
            s["Body"],
        ),
        p("Analytics", s["H2"]),
        p(
            "Visit and outbound click counts for published landings. The counts stay on this site. Use the tables when "
            "a chart is hard to read.",
            s["Body"],
        ),
        p("Templates", s["H2"]),
        p(
            "One template is usually one service line or page design. Edit the blueprint, then deploy, to update the "
            "pages that came from it. Preview is for people who can edit the template.",
            s["Body"],
        ),
        p("Landings and service areas", s["H2"]),
        p(
            "Landings are the city pages. Service areas are the hub pages. A one-off edit on a single page lasts until "
            "the next deploy of that template and place, which rewrites the page from the template. Leave the stored "
            "template and place in place so later deploys find the same page.",
            s["Body"],
        ),
        p("Location library", s["H2"]),
        p(
            "Add, import, and export places. The danger zone deletes places. That delete cannot be undone from this screen.",
            s["Body"],
        ),
        p("Deploy", s["H2"]),
        p(
            "Choose a template and write one current page per place inside the service-area circles. Health check reports "
            "duplicates and pages that lost their template or place link. It does not publish new pages by itself.",
            s["Body"],
        ),
        p("Import / export", s["H2"]),
        p(
            "Spintax and template slots fill variation blocks. The locations tab is the same library as Location library. "
            "Use the migration tab only when you are moving an older Magic Page library.",
            s["Body"],
        ),
        p("Settings", s["H2"]),
        p(
            "License stores the key. General holds the service-area URL prefix and the deploy batch size. Lower the batch "
            "size when a host times out. Service areas holds the circles. Site replacers are tokens such as company-name "
            "and phone-number. An area override wins when a place falls inside that circle. Content and rotation should "
            "stay off unless you want spintax rewritten on a timer. Database is for leftover Magic Page options. "
            "Integrations turns on Elementor or Beaver Builder and copies matching meta fields onto each landing.",
            s["Body"],
        ),
        p("Logs", s["H2"]),
        p(
            "Open Logs after a deploy or import reports a server error. The processing log is the run. The error log is "
            "the failure. Logs stay on the site until a confirmed delete at deactivation.",
            s["Body"],
        ),
        p("Support", s["H2"]),
        p(
            "An administrator opens Radius, then Support, describes what they were trying to do and what happened, and "
            "clicks Email support. The message goes to support@duppinstech.com. It includes the name, email, screen, subject, "
            "what they were trying to do, what happened, the site address, the WordPress version, and the Radius version. "
            "Radius does not keep a copy. Wait a minute before sending another "
            "message. If the site cannot send mail, email that address directly.",
            s["Body"],
        ),
        p("4. Tokens", s["H"]),
        p(
            "Write a token as {{token}} or [token]. Keys are lowercase. Hyphens are allowed, so {{company-name}} is valid. "
            "Place tokens come from the library (city, state, and similar fields). Site replacers come from Settings. "
            "Spintax is {one|two|three} and is resolved when the page is deployed.",
            s["Body"],
        ),
        p("5. Deactivate", s["H"]),
        p(
            "Plugins, then Deactivate, asks whether to keep Radius data or delete it. Cancel, Close, Escape, or a click "
            "outside leaves the plugin active and deletes nothing. Keep all data and deactivate turns the plugin off and "
            "leaves pages, places, templates, and settings stored. Delete all Radius data shows the counts. Confirm delete "
            "removes them, then deactivates. If a delete stops, the plugin stays active. Pages already removed stay removed. "
            "Deleting the plugin files later does not remove data that was kept.",
            s["Body"],
        ),
        p("6. Plans", s["H"]),
        p(
            "Single site is $9.99 per month. Unlimited sites is $99 per month. Both links are on Settings, then License. "
            "Priority email for the unlimited plan is handled in the Duppins Technology account. The plugin does not "
            "store or check that flag.",
            s["Body"],
        ),
        p(
            "Copyright Duppins Technology. Radius SEO is licensed under the GNU General Public License, version 2 or later. "
            "This manual describes the product. It is not a warranty.",
            s["Body"],
        ),
    ]
    OUT.parent.mkdir(parents=True, exist_ok=True)
    doc = SimpleDocTemplate(
        str(OUT),
        pagesize=letter,
        title="Radius SEO user manual",
        author="Duppins Technology",
        leftMargin=0.85 * inch,
        rightMargin=0.85 * inch,
        topMargin=0.75 * inch,
        bottomMargin=0.75 * inch,
    )
    doc.build(story)
    print(OUT)


if __name__ == "__main__":
    build()
