"""Build the illustrated user manual from marketing/guide. The PDF ships. This script does not."""

import re
from pathlib import Path

from PIL import Image as PILImage
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import inch
from reportlab.platypus import Image, PageBreak, Paragraph, Preformatted, SimpleDocTemplate, Spacer

ROOT = Path(__file__).resolve().parents[1]
GUIDE = ROOT / "marketing" / "guide"
OUT = ROOT / "assets" / "manual" / "radius-seo-user-manual.pdf"
IMAGE_RE = re.compile(r"!\[([^\]]*)\]\(([^)]+)\)")
LINK_RE = re.compile(r"\[([^\]]+)\]\(([^)]+)\)")


def styles():
    base = getSampleStyleSheet()
    base.add(ParagraphStyle(name="CoverTitle", parent=base["Title"], fontName="Times-Bold", fontSize=26, leading=30, spaceAfter=10))
    base.add(ParagraphStyle(name="Body", parent=base["Normal"], fontName="Times-Roman", fontSize=11, leading=15, spaceAfter=8))
    base.add(ParagraphStyle(name="H", parent=base["Heading1"], fontName="Times-Bold", fontSize=16, leading=20, spaceBefore=12, spaceAfter=8))
    base.add(ParagraphStyle(name="H2", parent=base["Heading2"], fontName="Times-Bold", fontSize=13, leading=16, spaceBefore=10, spaceAfter=6))
    base.add(ParagraphStyle(name="Cap", parent=base["Normal"], fontName="Times-Italic", fontSize=10, leading=13, spaceBefore=2, spaceAfter=4, leftIndent=12))
    base.add(ParagraphStyle(name="GuideCode", parent=base["Code"], fontName="Courier", fontSize=9, leading=12, spaceAfter=8))
    return base


def picture(path):
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


def xml_escape(text):
    return text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


def inline(text):
    text = xml_escape(text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<b>\1</b>", text)
    text = re.sub(r"`([^`]+)`", r"<font face='Courier'>\1</font>", text)

    def link(match):
        label, href = match.group(1), match.group(2)
        if href.startswith("http://") or href.startswith("https://"):
            return f'{label} ({href})'
        return label

    return LINK_RE.sub(link, text)


def plugin_version():
    header = (ROOT / "radius.php").read_text(encoding="utf-8")
    match = re.search(r"^\s*\*\s*Version:\s*(\S+)", header, re.M)
    return match.group(1) if match else ""


def add_paragraph(story, style, text):
    text = text.strip()
    if text:
        story.append(Paragraph(inline(text), style["Body"]))


def render_markdown(story, style, page, text, first):
    if not first:
        story.append(PageBreak())
    lines = text.splitlines()
    paragraph = []
    index = 0

    def flush():
        add_paragraph(story, style, " ".join(paragraph))
        paragraph.clear()

    while index < len(lines):
        raw = lines[index]
        line = raw.strip()
        if line.startswith("<!--"):
            index += 1
            continue
        if line.startswith("```"):
            flush()
            index += 1
            block = []
            while index < len(lines) and not lines[index].strip().startswith("```"):
                block.append(lines[index])
                index += 1
            story.append(Preformatted("\n".join(block), style["GuideCode"]))
            index += 1
            continue
        image = IMAGE_RE.fullmatch(line)
        if image:
            flush()
            path = (page.parent / image.group(2)).resolve()
            img = picture(path)
            if img is not None:
                story.append(Spacer(1, 6))
                story.append(img)
                story.append(Paragraph(inline(image.group(1)), style["Cap"]))
            index += 1
            continue
        if line.startswith("# "):
            flush()
            story.append(Paragraph(inline(line[2:]), style["H"]))
            index += 1
            continue
        if line.startswith("## "):
            flush()
            story.append(Paragraph(inline(line[3:]), style["H2"]))
            index += 1
            continue
        if line.startswith("|"):
            flush()
            rows = []
            while index < len(lines) and lines[index].strip().startswith("|"):
                cells = [cell.strip() for cell in lines[index].strip().strip("|").split("|")]
                if not all(set(cell) <= set("-: ") for cell in cells):
                    rows.append(" — ".join(cells))
                index += 1
            for row in rows:
                story.append(Paragraph(inline(row), style["Body"]))
            continue
        if line == "":
            flush()
            index += 1
            continue
        if re.match(r"^(\d+\.|-)\s+", line):
            flush()
            story.append(Paragraph(inline(line), style["Body"]))
            index += 1
            continue
        paragraph.append(line)
        index += 1
    flush()


def build():
    style = styles()
    order = (GUIDE / "order.txt").read_text(encoding="utf-8").splitlines()
    version = plugin_version()
    story = [
        Spacer(1, 1.2 * inch),
        Paragraph("Radius SEO", style["CoverTitle"]),
        Paragraph("User guide", style["H"]),
        Paragraph("Duppins Technology", style["Body"]),
        Paragraph(f"Version {version}", style["Body"]),
        Spacer(1, 0.3 * inch),
        Paragraph(
            "This guide walks every wp-admin screen. Red numbers on a screenshot match the numbered notes under that picture. "
            "The same pages are published at https://duppinstech.com/radius-seo/guide/ . "
            "Help, at the bottom-right of a Radius screen, opens the section for that screen.",
            style["Body"],
        ),
        Paragraph(
            "The usual order is: install, save a license, import places, draw a service area, write one template, then deploy. "
            "Deploy updates the existing page for a place. It does not add a second copy.",
            style["Body"],
        ),
        Paragraph(
            "Copyright Duppins Technology. This guide describes the product. It is not a warranty.",
            style["Body"],
        ),
        PageBreak(),
    ]
    first = True
    for name in order:
        name = name.strip()
        if not name or name == "Home.md":
            continue
        page = GUIDE / name
        render_markdown(story, style, page, page.read_text(encoding="utf-8"), first)
        first = False
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
