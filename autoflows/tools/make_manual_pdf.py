#!/usr/bin/env python3
"""AutoFlows User Manual -> PDF (ReportLab)"""
import re, os
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm, cm
from reportlab.lib.colors import HexColor, white, black
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.enums import TA_LEFT, TA_CENTER, TA_JUSTIFY
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle,
                                PageBreak, HRFlowable, Preformatted, KeepTogether, ListFlowable, ListItem)
from reportlab.lib import colors
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

SRC = os.path.join(os.path.dirname(__file__), "..", "USER_MANUAL.md")
DST = os.path.join(os.path.dirname(__file__), "..", "AutoFlows_User_Manual.pdf")

ACCENT = HexColor("#6C2EFF")
ACCENT_DARK = HexColor("#4A1DBF")
DARK = HexColor("#111827")
GRAY = HexColor("#6B7280")
LIGHT_BG = HexColor("#F3F4F6")
CODE_BG = HexColor("#F8F7FF")
GREEN = HexColor("#059669")
YELLOW_BG = HexColor("#FFFBEB")
BORDER = HexColor("#E5E7EB")

W, H = A4

styles = getSampleStyleSheet()
sTitle = ParagraphStyle("Title2", parent=styles["Title"], fontSize=32, leading=36, textColor=DARK, alignment=TA_CENTER, spaceAfter=6)
sSubtitle = ParagraphStyle("Subtitle2", parent=styles["Normal"], fontSize=12, leading=16, textColor=GRAY, alignment=TA_CENTER, spaceAfter=4)
sH1 = ParagraphStyle("H1", parent=styles["Heading1"], fontSize=16, leading=20, textColor=ACCENT_DARK, spaceBefore=18, spaceAfter=8, keepWithNext=True, borderPadding=(0,0,4,0))
sH2 = ParagraphStyle("H2", parent=styles["Heading2"], fontSize=13, leading=17, textColor=DARK, spaceBefore=14, spaceAfter=6, keepWithNext=True)
sH3 = ParagraphStyle("H3", parent=styles["Heading3"], fontSize=11, leading=15, textColor=HexColor("#374151"), spaceBefore=10, spaceAfter=4)
sBody = ParagraphStyle("Body", parent=styles["Normal"], fontSize=9.5, leading=14.5, textColor=HexColor("#1F2937"), alignment=TA_JUSTIFY, spaceAfter=5)
sBullet = ParagraphStyle("Bullet", parent=sBody, leftIndent=16, firstLineIndent=0, bulletIndent=8, alignment=TA_LEFT)
sQuote = ParagraphStyle("Quote", parent=sBody, leftIndent=12, textColor=HexColor("#4B5563"), borderPadding=(6,6,6,12), backColor=YELLOW_BG, alignment=TA_LEFT)
sCode = ParagraphStyle("Code", parent=styles["Code"], fontSize=8.2, leading=12, fontName="Courier", textColor=HexColor("#1F2937"), alignment=TA_LEFT)
sCell = ParagraphStyle("Cell", parent=styles["Normal"], fontSize=8.5, leading=11.5, textColor=HexColor("#1F2937"), alignment=TA_LEFT)
sCellH = ParagraphStyle("CellH", parent=sCell, textColor=white, fontName="Helvetica-Bold")
sCaption = ParagraphStyle("Caption", parent=styles["Normal"], fontSize=8, leading=11, textColor=GRAY, alignment=TA_CENTER, spaceAfter=8)
sTOC = ParagraphStyle("TOC", parent=styles["Normal"], fontSize=9.5, leading=15, textColor=DARK, leftIndent=12)
sFooter = ParagraphStyle("Footer", parent=styles["Normal"], fontSize=7.5, textColor=white, alignment=TA_CENTER)

def esc(t):
    return t.replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")

def inline_md(t):
    t = esc(t)
    # links [text](url) -> keep external links, flatten internal # anchors (no PDF dest)
    def _link(m):
        txt, url = m.group(1), m.group(2)
        if url.startswith("#"):
            return txt
        return f'<a href="{url}">{txt}</a>'
    t = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', _link, t)
    # inline code
    t = re.sub(r'`([^`]+)`', r'<font face="Courier" backColor="#F3F4F6"> \1 </font>', t)
    # bold
    t = re.sub(r'\*\*([^*]+)\*\*', r'<b>\1</b>', t)
    # italic single * (avoid bullets)
    # t = re.sub(r'(?<!\w)\*([^*\n]+)\*(?!\w)', r'<i>\1</i>', t)
    return t

def header_footer(canvas, doc):
    canvas.saveState()
    if doc.page > 1:
        canvas.setFillColor(ACCENT_DARK)
        canvas.rect(0, H-14*mm, W, 14*mm, fill=1, stroke=0)
        canvas.setFillColor(white)
        canvas.setFont("Helvetica-Bold", 8)
        canvas.drawString(15*mm, H-8.5*mm, "AutoFlows Content Studio — User Manual")
        canvas.setFont("Helvetica", 7.5)
        canvas.drawRightString(W-15*mm, H-8.5*mm, "v1.0.0  •  Oct 2026")
        canvas.setFillColor(HexColor("#9CA3AF"))
        canvas.setFont("Helvetica", 7.5)
        canvas.drawCentredString(W/2, 12*mm, f"Page {doc.page}")
    canvas.restoreState()

with open(SRC, encoding="utf-8") as f:
    lines = f.read().splitlines()

story = []
# ---- Cover ----
story.append(Spacer(1, 28*mm))
story.append(Paragraph("AutoFlows", ParagraphStyle("cover1", parent=sTitle, textColor=ACCENT, fontSize=20, alignment=TA_CENTER, spaceAfter=2)))
story.append(Paragraph("Content Studio", ParagraphStyle("cover2", parent=sTitle, fontSize=34, spaceAfter=4)))
story.append(Paragraph("USER MANUAL", ParagraphStyle("cover3", parent=styles["Normal"], fontSize=13, leading=16, textColor=GRAY, alignment=TA_CENTER, spaceAfter=6)))
story.append(HRFlowable(width="30%", thickness=2, color=ACCENT, spaceAfter=8, spaceBefore=4, hAlign="CENTER"))
story.append(Paragraph("One brief in, three channels out: daily social posts,<br/>a blog article, and a marketing email.", sSubtitle))
story.append(Spacer(1, 6*mm))
box_data = [[Paragraph("<b><font color='#FFFFFF'>Version 1.0.0 &nbsp;|&nbsp; Oct 05, 2026 &nbsp;|&nbsp; PHP 8.1+ &nbsp;•&nbsp; SQLite &nbsp;•&nbsp; Offline-first</font></b>", ParagraphStyle("box", parent=styles["Normal"], fontSize=8.5, textColor=white, alignment=TA_CENTER))]]
box = Table(box_data, colWidths=[130*mm])
box.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),ACCENT_DARK),("ROUNDEDCORNERS",[4,4,4,4]),("VALIGN",(0,0),(-1,-1),"MIDDLE"),("TOPPADDING",(0,0),(-1,-1),8),("BOTTOMPADDING",(0,0),(-1,-1),8),("LEFTPADDING",(0,0),(-1,-1),10),("RIGHTPADDING",(0,0),(-1,-1),10)]))
story.append(box)
story.append(Spacer(1, 8*mm))
story.append(Paragraph("Dashboard &nbsp;•&nbsp; TinyLLM Chat &nbsp;•&nbsp; Flows &nbsp;•&nbsp; FlowAgent &nbsp;•&nbsp; Content Library &nbsp;•&nbsp; Gmail + Facebook Publishing &nbsp;•&nbsp; Daily Automation", ParagraphStyle("coverfeat", parent=styles["Normal"], fontSize=8, textColor=GRAY, alignment=TA_CENTER)))
story.append(Spacer(1, 10*mm))
story.append(Paragraph("Self-hosted &nbsp;|&nbsp; No build step &nbsp;|&nbsp; No Composer &nbsp;|&nbsp; MIT License", sCaption))
story.append(Paragraph("Start: &nbsp;<font face='Courier'>php -S 127.0.0.1:8020 -t public public/router.php</font> &nbsp;→&nbsp; http://127.0.0.1:8020", sCaption))

in_code = False
code_buf = []
table_buf = []
in_table = False

def flush_code():
    global code_buf
    if code_buf:
        txt = "\n".join(code_buf)
        # Preformatted with background via table
        p = Preformatted(esc(txt), sCode)
        t = Table([[p]], colWidths=[170*mm])
        t.setStyle(TableStyle([("BACKGROUND",(0,0),(-1,-1),CODE_BG),("BOX",(0,0),(-1,-1),0.6,BORDER),("LEFTPADDING",(0,0),(-1,-1),8),("RIGHTPADDING",(0,0),(-1,-1),8),("TOPPADDING",(0,0),(-1,-1),6),("BOTTOMPADDING",(0,0),(-1,-1),6),("ROUNDEDCORNERS",[3,3,3,3])]))
        story.append(t)
        story.append(Spacer(1, 3*mm))
        code_buf = []

def flush_table():
    global table_buf
    if not table_buf:
        return
    rows = []
    for i, r in enumerate(table_buf):
        cells = [c.strip() for c in r.strip().strip("|").split("|")]
        # skip separator row
        if all(set(c) <= set("-: ") for c in cells):
            continue
        prows = [Paragraph(inline_md(c), sCellH if i==0 else sCell) for c in cells]
        rows.append(prows)
    if rows:
        ncols = len(rows[0])
        cw = [170*mm/ncols]*ncols
        t = Table(rows, colWidths=cw, repeatRows=1)
        style = [("BACKGROUND",(0,0),(-1,0),ACCENT_DARK),("TEXTCOLOR",(0,0),(-1,0),white),
                 ("GRID",(0,0),(-1,-1),0.5,BORDER),("VALIGN",(0,0),(-1,-1),"TOP"),
                 ("TOPPADDING",(0,0),(-1,-1),4),("BOTTOMPADDING",(0,0),(-1,-1),4),
                 ("LEFTPADDING",(0,0),(-1,-1),5),("RIGHTPADDING",(0,0),(-1,-1),5),
                 ("ROWBACKGROUNDS",(0,1),(-1,-1),[white, HexColor("#F9FAFB")])]
        t.setStyle(TableStyle(style))
        story.append(t)
        story.append(Spacer(1, 3*mm))
    table_buf = []

first_h1_done = False
for raw in lines:
    line = raw.rstrip("\n")
    if line.strip().startswith("```"):
        if in_code:
            flush_code(); in_code=False
        else:
            flush_table(); in_code=True
        continue
    if in_code:
        code_buf.append(line)
        continue
    # table detection
    if re.match(r'^\s*\|.*\|\s*$', line) and "|" in line:
        table_buf.append(line); continue
    else:
        if table_buf: flush_table()
    s = line.strip()
    if not s:
        story.append(Spacer(1, 1.5*mm)); continue
    if s == "---":
        story.append(HRFlowable(width="100%", thickness=0.6, color=BORDER, spaceAfter=4, spaceBefore=4)); continue
    m = re.match(r'^(#{1,3})\s+(.*)', s)
    if m:
        lvl = len(m.group(1)); title = m.group(2).strip()
        # skip first doc title (already cover) and TOC heading handled normally
        if lvl == 1 and not first_h1_done and "User Manual" in title:
            story.append(PageBreak()); first_h1_done=True; continue
        if lvl == 1:
            story.append(Paragraph(inline_md(title), sH1))
            story.append(HRFlowable(width="100%", thickness=1.2, color=ACCENT, spaceAfter=4, spaceBefore=2, hAlign="LEFT"))
        elif lvl == 2:
            story.append(Paragraph(inline_md(title), sH2))
        else:
            story.append(Paragraph(inline_md(title), sH3))
        continue
    if s.startswith("> "):
        story.append(Paragraph(inline_md(s[2:]), sQuote)); continue
    bm = re.match(r'^[-*]\s+(.*)', s)
    if bm:
        story.append(Paragraph("• &nbsp;" + inline_md(bm.group(1)), sBullet)); continue
    nm = re.match(r'^(\d+)[.)]\s+(.*)', s)
    if nm:
        story.append(Paragraph(f"<b>{nm.group(1)}.</b> &nbsp;" + inline_md(nm.group(2)), sBullet)); continue
    # normal paragraph
    # handle **label:** lines fine as body
    story.append(Paragraph(inline_md(s), sBody))

flush_table(); flush_code()

doc = SimpleDocTemplate(DST, pagesize=A4, leftMargin=20*mm, rightMargin=20*mm, topMargin=20*mm, bottomMargin=16*mm, title="AutoFlows Content Studio - User Manual", author="AutoFlows")
doc.build(story, onFirstPage=header_footer, onLaterPages=header_footer)
print(f"PDF written: {DST} ({os.path.getsize(DST)} bytes)")
