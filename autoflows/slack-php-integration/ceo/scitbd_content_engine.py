# -*- coding: utf-8 -*-
"""
SCITBD AI CEO — Daily Content, Ads & Task-Digest Engine
=======================================================
Generates and stores, per calendar day (Asia/Dhaka):

  * 5 SEO-friendly social posts EACH for Facebook, YouTube, LinkedIn
  * 5 SEO-friendly blog titles (service-promotion)
  * 5 SEO-friendly sales emails
  * Online Ads DISPLAY features  (placements per platform)
  * Online Ads EARNING models    (CPC/CPM/CPA/affiliate/...)

Then:
  * assigns the review tasks to the AI Agent (daily_tasks.assignee='ai_agent')
  * writes daily content FILES under content/<date>/
  * builds an HTML email digest with clickable SELECT links so the CEO can
    pick today's task straight from the inbox
  * supports a T-30min pre-workflow trigger (05:30/11:30/17:30/23:30 BST)

CLI
---
    python scitbd_content_engine.py install    # create tables + seed ads
    python scitbd_content_engine.py generate   # content rows only (idempotent)
    python scitbd_content_engine.py tasks      # assign AI-agent tasks only
    python scitbd_content_engine.py files      # write content/<date>/ files
    python scitbd_content_engine.py email      # build HTML digest only
    python scitbd_content_engine.py all        # everything above
    python scitbd_content_engine.py preview    # print digest to stdout
    python scitbd_content_engine.py tminus     # T-30 trigger (for scheduler)
"""
from __future__ import annotations

import datetime
import html
import json
import os
import pathlib
import sys
import zoneinfo

try:
    import sqlite3
except ImportError:                                       # pragma: no cover
    sqlite3 = None

DB_PATH = r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db"
ROOT = pathlib.Path(r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy")
CONTENT_DIR = ROOT / "content"
EMAIL_DIR = ROOT / "emails"
LOG_DIR = ROOT / "logs"
BST = zoneinfo.ZoneInfo("Asia/Dhaka")

CRM_URL = os.environ.get("SCITBD_CRM_URL", "https://scit.zya.me/")
CEO_EMAIL = os.environ.get("SCITBD_CEO_EMAIL", "ceo@scitbd.com")

PLATFORMS = ("facebook", "youtube", "linkedin")
N_SUGGESTIONS = 5

# Block starts (BST) -> T-30 triggers
BLOCK_STARTS = ("00:00", "06:00", "12:00", "18:00")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def connect():
    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys=ON")
    return con


def today(now: datetime.datetime | None = None) -> datetime.date:
    return (now or datetime.datetime.now(BST)).astimezone(BST).date()


# ══════════════════════════════════════════════════════════
# SCHEMA
# ══════════════════════════════════════════════════════════
SCHEMA = """
CREATE TABLE IF NOT EXISTS content_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    content_date  TEXT NOT NULL,
    content_type  TEXT NOT NULL,          -- 'social' | 'blog' | 'email'
    platform      TEXT NOT NULL,          -- 'facebook'|'youtube'|'linkedin'|'blog'|'email'
    seq           INTEGER NOT NULL,       -- 1..5
    title         TEXT NOT NULL,
    body          TEXT NOT NULL,
    primary_keyword TEXT,
    secondary_keywords TEXT,              -- comma separated
    hashtags      TEXT,                   -- space separated
    slug          TEXT,
    meta_description TEXT,
    cta           TEXT,
    audience      TEXT,
    seo_score     INTEGER DEFAULT 0,
    status        TEXT DEFAULT 'suggested',
    file_path     TEXT,
    created_by    TEXT DEFAULT 'ai_agent',
    created_at    TEXT DEFAULT (datetime('now','localtime')),
    UNIQUE(content_date, content_type, platform, seq)
);

CREATE TABLE IF NOT EXISTS ad_models (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    model_code    TEXT NOT NULL UNIQUE,   -- CPC, CPM, CPA, CPD, CPA, AFFILIATE...
    model_name    TEXT NOT NULL,
    model_family  TEXT NOT NULL,          -- 'earning' | 'buying'
    payout_basis  TEXT,                   -- 'per click','per 1000 impressions',...
    typical_rate  REAL,
    rate_currency TEXT DEFAULT 'USD',
    platforms     TEXT,                   -- comma separated
    description   TEXT,
    is_active     INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS ad_features (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    feature_name  TEXT NOT NULL UNIQUE,
    feature_kind  TEXT NOT NULL,          -- 'display' | 'earning'
    platform      TEXT NOT NULL,
    placement     TEXT,
    format_spec   TEXT,
    size          TEXT,
    model_id      INTEGER,
    revenue_share REAL,
    description   TEXT,
    is_active     INTEGER DEFAULT 1,
    FOREIGN KEY (model_id) REFERENCES ad_models(id)
);
"""


def install() -> None:
    con = connect()
    con.executescript(SCHEMA)
    _seed_ad_models(con)
    _seed_ad_features(con)
    con.commit()
    con.close()
    print("✔ schema installed (content_items, ad_models, ad_features)")


# ── Online Ads: EARNING models ────────────────────────────
AD_MODELS = [
    # code, name, family, basis, typical_rate, platforms, description
    ("CPC", "Cost Per Click", "earning", "per ad click", 0.35,
     "facebook,youtube,linkedin,blog",
     "Earn when a visitor clicks a served ad. Best for high-intent blog traffic "
     "and mid-funnel social placements."),
    ("CPM", "Cost Per Mille (per 1000 impressions)", "earning",
     "per 1000 impressions", 2.80,
     "facebook,youtube,linkedin,blog",
     "Earn on volume of eyes. Strongest on video pre-roll (YouTube) and "
     "above-the-fold display banners."),
    ("CPA", "Cost Per Action / Acquisition", "earning",
     "per qualified action", 22.00,
     "facebook,linkedin,blog",
     "Earn on signup, download or purchase. Highest payout, lowest volume — "
     "pair with gated case studies."),
    ("CPV", "Cost Per View (video)", "earning", "per 30s video view", 0.12,
     "youtube,facebook",
     "YouTube Partner / in-stream style payout for completed or 30-second "
     "qualified views."),
    ("AFFILIATE", "Affiliate Revenue Share", "earning",
     "per referred sale + rev-share", 15.00,
     "blog,facebook,linkedin",
     "Commission on referred sales plus recurring revenue share on retainers "
     "closed from your links."),
    ("SPONSORED", "Sponsored Post / Native Placement", "earning",
     "flat fee per placement", 250.00,
     "linkedin,facebook,blog",
     "Flat-fee native advertorial slots sold directly to advertisers — no "
     "middleman, highest margin."),
    ("PROGRAMMATIC", "Programmatic Display (RTB)", "earning",
     "blended eCPM", 1.90,
     "blog,facebook,linkedin",
     "Real-time-bidding header auction across SSPs; fills unsold inventory "
     "automatically."),
    ("PPC", "Paid Search (buying)", "buying", "per click", 1.80,
     "google,linkedin",
     "Outbound spend model for Google Search / LinkedIn Sponsored — used to "
     "buy, not earn, traffic."),
]

# ── Online Ads: DISPLAY features / placements ─────────────
AD_FEATURES = [
    # name, kind, platform, placement, format, size, model_code, rev_share, desc
    ("Facebook Feed Banner", "display", "facebook", "in-feed",
     "image + primary text", "1200x628", "CPM", 0.55,
     "Scroll-stopping image card inside the mobile/desktop news feed."),
    ("Facebook Carousel Ad", "display", "facebook", "in-feed",
     "carousel (up to 5 cards)", "1080x1080 each", "CPC", 0.55,
     "Multi-card storytelling — ideal for showcasing the 17 service lines."),
    ("Facebook Reels Overlay", "display", "facebook", "reels",
     "vertical video + CTA", "1080x1920", "CPV", 0.55,
     "9:16 short-form placement riding the Reels discovery surface."),
    ("YouTube Pre-Roll In-Stream", "display", "youtube", "in-stream",
     "skippable video", "1920x1080", "CPV", 0.55,
     "Ad plays before target content; earns on 30s-qualified views."),
    ("YouTube Overlay Banner", "display", "youtube", "display",
     "lower-third banner", "300x50", "CPC", 0.55,
     "Non-intrusive companion banner beside the video player."),
    ("YouTube Shorts Monetization", "earning", "youtube", "shorts",
     "vertical short-form", "1080x1920", "CPM", 0.55,
     "Revenue share on Shorts feed impressions via partner programme."),
    ("LinkedIn Sponsored Content", "display", "linkedin", "feed",
     "single image / article", "1200x627", "CPC", 0.65,
     "Native B2B placement targeting CTO/CIO seniority and firmographics."),
    ("LinkedIn Thought-Lead Ads", "display", "linkedin", "feed",
     "lead-gen form native", "1200x627", "CPA", 0.65,
     "Native form opens pre-filled — highest B2B conversion placement."),
    ("LinkedIn Newsletter Slot", "earning", "linkedin", "newsletter",
     "in-newsletter native", "inline", "SPONSORED", 0.65,
     "Sponsored slot inside your LinkedIn newsletter edition."),
    ("Blog Display Banner", "display", "blog", "above-the-fold",
     "responsive display", "728x90 / 300x250", "CPM", 0.70,
     "Leaderboard + sidebar MPU served on every article page."),
    ("Blog In-Article Native", "display", "blog", "in-content",
     "native in-article", "fluid", "PROGRAMMATIC", 0.70,
     "Blended recommendation widget mid-article; highest viewability."),
    ("Blog Sticky Footer", "display", "blog", "sticky-footer",
     "anchored banner", "728x90", "CPC", 0.70,
     "Persistent bottom anchor — steady viewability without blocking reads."),
    ("Blog Gated Lead Magnet", "earning", "blog", "gate",
     "gated asset form", "inline", "CPA", 0.70,
     "Case study / whitepaper gate earning CPA on each qualified download."),
    ("Email Signature Ad", "earning", "email", "signature",
     "footer native ad", "320x100", "AFFILIATE", 0.60,
     "Monetizes outbound email volume with a native signature placement."),
]

# ══════════════════════════════════════════════════════════
# SEO CONTENT GENERATION
# ══════════════════════════════════════════════════════════
SERVICES = [
    ("Custom ERP Development", "erp software development", "operations teams"),
    ("Cloud Transformation", "cloud migration services", "mid-market CTOs"),
    ("AI Business Automation", "ai automation for business", "ops leaders"),
    ("B2B SEO Services", "b2b seo agency", "marketing directors"),
    ("Corporate Video Production", "corporate video production", "tech brands"),
    ("Managed Hosting & SLA", "managed hosting 99.9 uptime", "IT managers"),
]

# ── 5 SEO blog titles per day (rotating pool) ─────────────
BLOG_TITLES = [
    ("Custom ERP Software Development: Cut Operational Costs by 40% in 2026",
     "erp software development",
     "Learn how custom ERP software development streamlines operations, "
     "eliminates spreadsheet chaos and cuts operational costs by up to 40%. "
     "See timelines, pricing and a step-by-step migration roadmap.",
     "custom-erp-software-development-cost-reduction"),
    ("Cloud Migration Services: A Step-by-Step Roadmap for Mid-Market Firms",
     "cloud migration services",
     "A practical cloud migration services roadmap covering assessment, "
     "lift-and-shift vs replatform, security, downtime avoidance and "
     "post-migration cost governance.",
     "cloud-migration-services-roadmap"),
    ("AI Automation for Business: 7 Workflows That Reclaim 20+ Hours Weekly",
     "ai automation for business",
     "Discover 7 proven ai automation for business workflows — lead routing, "
     "proposal drafting, SLA alerts and reporting — that reclaim 20+ hours "
     "every week.",
     "ai-automation-for-business-workflows"),
    ("B2B SEO Agency Playbook: Rank Faster with Topic Clusters (2026)",
     "b2b seo agency",
     "This b2b seo agency playbook shows how topic clusters, internal linking "
     "and BOFU keyword targeting compound organic traffic in under 6 months.",
     "b2b-seo-agency-topic-cluster-playbook"),
    ("Corporate Video Production for Tech Brands: Turn Demos Into Pipeline",
     "corporate video production",
     "How corporate video production converts product demos into pipeline: "
     "script frameworks, hook timing, distribution and the metrics that "
     "actually predict closed-won.",
     "corporate-video-production-tech-brands"),
    ("Managed Hosting with 99.9% Uptime: What Your SLA Must Guarantee",
     "managed hosting 99.9 uptime",
     "Break down a real managed hosting SLA: uptime math, response vs "
     "resolution times, penalties and the monitoring stack behind 99.9% uptime.",
     "managed-hosting-99-uptime-sla"),
]

# ── 5 SEO social posts per platform ───────────────────────
SOCIAL = {
    "facebook": [
        ("Stop Losing Hours to Spreadsheets",
         "Your team deserves better than 12 open tabs and a broken VLOOKUP.\n\n"
         "We build custom ERP systems that unite finance, inventory and HR in "
         "ONE dashboard — deployed in weeks, not years.\n\n"
         "→ Get a free process audit this week.",
         "custom erp development"),
        ("Cloud Migration Without the Headache",
         "Downtime during migration? Not on our watch.\n\n"
         "Our 5-phase cloud migration roadmap moved 200+ workloads with zero "
         "revenue-blocking outages.\n\n"
         "→ Comment \"CLOUD\" and we'll send the checklist.",
         "cloud migration services"),
        ("7 Workflows We'd Automate First",
         "Lead routing. Proposal drafts. SLA alerts. Weekly reporting.\n\n"
         "Automating just 7 workflows reclaims 20+ hours a week across your "
         "team.\n\n"
         "→ Which task do you hate most? Tell us below.",
         "ai automation for business"),
        ("Your Website Ranks Page 2. Here's Why.",
         "Page 2 is page 1's invisible graveyard.\n\n"
         "Topic clusters + BOFU keywords + internal links = compounding "
         "organic traffic in under 6 months.\n\n"
         "→ Free 10-point SEO teardown, no strings.",
         "b2b seo agency"),
        ("Turn Your Demo Into Revenue",
         "A 90-second demo video outperforms a 9-page deck.\n\n"
         "We script, shoot and edit corporate video that sales actually sends "
         "— and buyers actually finish.\n\n"
         "→ See 3 samples in our reel.",
         "corporate video production"),
    ],
    "youtube": [
        ("Custom ERP Explained in 6 Minutes",
         "What does custom ERP software actually replace? In this 6-minute "
         "breakdown we map finance, inventory and HR onto a single data model "
         "— and show the exact cost of NOT unifying them.\n\n"
         "Chapters: 00:00 The spreadsheet tax | 01:30 Data model | "
         "03:40 Rollout timeline | 05:10 Real ROI\n\n"
         "Book a free process audit: link in description.",
         "custom erp development"),
        ("Cloud Migration: 5 Phases, Zero Downtime",
         "Most migrations fail on phase 2. Here's the full 5-phase framework "
         "we've used across 200+ workload moves — assessment, pilot, "
         "replatform, cutover, governance.\n\n"
         "Free cloud readiness checklist in the description.",
         "cloud migration services"),
        ("Automate These 7 Workflows First",
         "Ranking the 7 highest-ROI business workflows to automate first — "
         "and the 3 you should NEVER automate. Timestamps and templates in "
         "the description.\n\n"
         "Which workflow costs you the most hours? Comment below.",
         "ai automation for business"),
        ("Why Your B2B Content Never Ranks",
         "If your content targets keywords nobody buys from, page 4 is your "
         "ceiling. We rebuild the topic cluster live in this video — from "
         "pillar page to 12 supporting posts.\n\n"
         "Steal our cluster template: link in description.",
         "b2b seo agency"),
        ("The Demo Video Script Framework",
         "Hook in 3 seconds. Problem in 15. Proof by 45. CTA by 60.\n\n"
         "The exact corporate video script framework behind a 34% demo-to-"
         "meeting rate — with a downloadable outline.",
         "corporate video production"),
    ],
    "linkedin": [
        ("The Spreadsheet Tax Is Real",
         "A client came to us running procurement across 14 spreadsheets.\n\n"
         "Reconciliation took 3 days/month. Errors were invisible until "
         "quarter-end.\n\n"
         "We replaced it with one custom ERP module:\n"
         "→ 3 days → 40 minutes\n"
         "→ Error rate → near zero\n"
         "→ Payback → 5 months\n\n"
         "Unify the data model before you automate anything else.",
         "custom erp development"),
        ("200 Workloads. Zero Downtime.",
         "The scary part of cloud migration isn't the move.\n\n"
         "It's the rollback plan you never tested.\n\n"
         "Our cutover sequence: pilot → parallel run → "
         "verified failback → only then production.\n\n"
         "That's how you get zero revenue-blocking outages.",
         "cloud migration services"),
        ("20 Hours a Week, Recovered",
         "We audited an ops team's week and found 21 hours spent on "
         "copy-paste work.\n\n"
         "Seven automations later:\n"
         "→ lead routing: instant\n"
         "→ proposals: drafted in 8 min\n"
         "→ reporting: scheduled\n\n"
         "Automate the repetitive. Protect the judgment calls.",
         "ai automation for business"),
        ("Page 2 Is Where Good Companies Die",
         "Your best article is ranking #14.\n\n"
         "Nobody scrolls there.\n\n"
         "The fix is rarely more content — it's a tighter topic cluster, "
         "BOFU intent targeting, and internal links that actually pass "
         "authority.\n\n"
         "We'd rather fix 10 pages than publish 40 more.",
         "b2b seo agency"),
        ("Buyers Watch. Stakeholders Skim.",
         "A 9-page technical deck gets forwarded.\n\n"
         "A 90-second demo video gets watched — and forwarded WITH context.\n\n"
         "That's the difference between being evaluated and being understood.",
         "corporate video production"),
    ],
}

# ── 5 SEO sales emails ────────────────────────────────────
EMAILS = [
    ("Your spreadsheets are costing you 3 days a month",
     "process-automation-audit",
     "operations leaders",
     "Noticed your team still reconciling numbers by hand?",
     "Most operations teams lose 3 full days a month to manual "
     "reconciliation.\n\n"
     "We mapped one client's 14 spreadsheets into a single custom ERP "
     "module and cut that to 40 minutes.\n\n"
     "I'll run the same teardown on one process of yours — free, 20 minutes, "
     "no slides.\n\n"
     "Grab a slot this week?",
     "Book my free process audit"),
    ("The migration checklist we use for 200+ workloads",
     "cloud-readiness-checklist",
     "mid-market CTOs",
     "Sending the 5-phase cloud migration checklist you asked about",
     "Downtime is the #1 fear in every migration conversation I have.\n\n"
     "Our 5-phase sequence (assess → pilot → replatform → cutover → govern) "
     "moved 200+ workloads with zero revenue-blocking outages.\n\n"
     "Here's the exact checklist we run internally. Want it applied to your "
     "environment?",
     "Send me the checklist"),
    ("7 automations. 20 hours back.",
     "workflow-automation-roi",
     "ops and functional leads",
     "What would your team do with 20 extra hours?",
     "We audited an ops team and found 21 hours/week of copy-paste work.\n\n"
     "Seven automations later — instant lead routing, 8-minute proposals, "
     "scheduled reporting.\n\n"
     "I'll do a no-obligation audit of your three most repetitive workflows "
     "and show the ROI before you commit to anything.",
     "Audit my workflows"),
    ("Your best article is ranking #14 (and why that matters)",
     "seo-topic-cluster-audit",
     "marketing directors",
     "Your best content is on page 2 — here's the fix",
     "Page 2 gets 0.6% of clicks.\n\n"
     "If your strongest article ranks #14, you're invisible for the work you "
     "did best.\n\n"
     "Usually the fix isn't more content — it's a tighter topic cluster, BOFU "
     "intent targeting, and internal links that pass real authority.\n\n"
     "I'll send back a 10-point teardown of your top 5 pages, free.",
     "Send my free SEO teardown"),
    ("Your demo deserves better than a forwarded PDF",
     "video-demo-conversion",
     "sales and marketing leaders",
     "The 90-second video your buyers would actually finish",
     "A 9-page deck gets forwarded without context.\n\n"
     "A 90-second demo gets watched — and forwarded WITH context.\n\n"
     "We script, shoot and edit corporate video tuned for the 3-second hook "
     "that keeps buyers watching.\n\n"
     "Three samples from your industry are attached. Worth a look?",
     "Show me the 3 samples"),
]


def _slug(s: str) -> str:
    out = "".join(c if c.isalnum() else "-" for c in s.lower())
    while "--" in out:
        out = out.replace("--", "-")
    return out.strip("-")[:90]


# Per-channel title / snippet norms used by _seo_score.
# A blog SERP title, a social scroll-hook and an inbox subject line are
# genuinely different artefacts — scoring all three against the blog rubric
# is why social/email previously averaged 29-44.
_CHANNEL_NORMS = {
    #  kind     : (title_lo, title_hi, snippet_lo, snippet_hi, snippet_label)
    "blog":     (45, 65, 120, 160, "meta description"),
    "social":   (20, 60, 60, 140, "caption preview"),
    "email":    (33, 55, 40, 90, "preview text"),
}


def _seo_score(title: str, body: str, keyword: str, meta: str = "",
               hashtags: str = "", kind: str = "blog") -> int:
    """
    Transparent 0-100 heuristic, calibrated PER CHANNEL.

    Keyword-on-page, hashtag use, formatting and CTA are shared signals.
    Title length and snippet length use each channel's own norms
    (SERP title vs scroll-hook vs inbox subject line).
    """
    t_lo, t_hi, s_lo, s_hi, _label = _CHANNEL_NORMS.get(
        kind, _CHANNEL_NORMS["blog"])

    score = 0
    kw = (keyword or "").lower()
    t, b = title.lower(), body.lower()

    # --- keyword relevance (35) ---
    if kw and kw in t:
        score += 20
    elif kw and any(w in t for w in kw.split()[:2]):
        score += 10
    if kw and kw in b:
        score += 15

    # --- title/subject/hook length for THIS channel (15) ---
    if t_lo <= len(title) <= t_hi:
        score += 15
    elif (t_lo - 12) <= len(title) <= (t_hi + 15):
        score += 8

    # --- snippet length for THIS channel (15) ---
    n = len(meta or "")
    if s_lo <= n <= s_hi:
        score += 15
    elif meta and (s_lo - 30) <= n <= (s_hi + 60):
        score += 7

    # --- body depth (10) ---
    if 80 <= len(body) <= 1400:
        score += 10
    elif body:
        score += 5

    # --- discoverability signals (15) ---
    if hashtags and len(hashtags.split()) >= 3:
        score += 8
    if body.count("\n") >= 2:
        score += 7

    # --- conversion signal: a real CTA (10) ---
    cta_hits = ("get ", "book ", "comment", "grab ", "claim", "send ",
                "start ", "try ", "->", "→", "learn more", "see ")
    if any(h in b for h in cta_hits):
        score += 10

    return min(score, 100)


def build_content(date_str: str) -> list[dict]:
    rows: list[dict] = []

    # ---- Social: 5 per platform ----
    for plat in PLATFORMS:
        for i, (title, body, kw) in enumerate(SOCIAL[plat][:N_SUGGESTIONS], 1):
            tags = (f"#SCITBD #{_slug(kw).replace('-', '')} "
                    f"#{plat.title()} #B2BGrowth #DigitalSolutions")
            meta = f"{title} — {_lead(body, 140)}"
            rows.append(dict(
                content_date=date_str, content_type="social", platform=plat,
                seq=i, title=title, body=body, primary_keyword=kw,
                secondary_keywords="b2b marketing, software services, seo",
                hashtags=tags, slug=_slug(title), meta_description=meta,
                cta=_extract_cta(body),
                audience=_audience_for(kw),
                seo_score=_seo_score(title, body, kw, meta, tags,
                                     kind="social")))

    # ---- Blogs: 5 titles ----
    for i, (title, kw, meta, slug) in enumerate(BLOG_TITLES[:N_SUGGESTIONS], 1):
        body = (f"Target keyword: {kw}\n"
                f"Audience: {SERVICES[i % len(SERVICES)][2]}\n\n"
                f"Outline:\n"
                f"  H1 {title}\n"
                f"  H2 The real cost of the status quo\n"
                f"  H2 How {SERVICES[i % len(SERVICES)][0]} works step by step\n"
                f"  H2 Metrics, timelines and pricing transparency\n"
                f"  H2 Common pitfalls and how to avoid them\n"
                f"  H2 Case snapshot + results\n"
                f"  CTA Book a scoping call\n\n"
                f"Word target: 1,800-2,200 | Internal links: 6 | "
                f"Schema: Article + FAQPage")
        tags = f"#SEO #{_slug(kw).replace('-', '')} #ContentMarketing #SCITBD"
        rows.append(dict(
            content_date=date_str, content_type="blog", platform="blog",
            seq=i, title=title, body=body, primary_keyword=kw,
            secondary_keywords="blog seo, organic traffic, service promotion",
            hashtags=tags, slug=slug, meta_description=meta,
            cta="Book a scoping call", audience="search intent (BOFU/MOFU)",
            seo_score=_seo_score(title, body, kw, meta, tags, kind="blog")))

    # ---- Emails: 5 ----
    for i, (subject, slug, aud, preview, body, cta) in enumerate(EMAILS, 1):
        kw = slug.replace("-", " ")
        meta = preview
        tags = f"#EmailMarketing #SalesEnablement #{_slug(kw).replace('-', '')}"
        full_body = f"Subject: {subject}\nPreview: {preview}\n\n{body}"
        rows.append(dict(
            content_date=date_str, content_type="email", platform="email",
            seq=i, title=subject, body=full_body, primary_keyword=kw,
            secondary_keywords="sales email, cold outreach, conversion copy",
            hashtags=tags, slug=slug, meta_description=preview,
            cta=cta, audience=aud,
            seo_score=_seo_score(subject, body, kw, preview, tags,
                                 kind="email")))
    return rows


def _lead(text: str, n: int) -> str:
    t = " ".join(text.split())
    return t if len(t) <= n else t[:n].rsplit(" ", 1)[0] + "…"


def _extract_cta(body: str) -> str:
    for line in reversed(body.split("\n")):
        s = line.strip().lstrip("→").strip()
        if s and len(s) < 70 and any(k in s.lower() for k in
                                     ("get", "book", "comment", "see", "send",
                                      "grab", "claim", "start", "try", "ask")):
            return s
    return "Learn more"


def _audience_for(kw: str) -> str:
    for name, k, aud in SERVICES:
        if k == kw:
            return aud
    return "B2B decision makers"


def generate(date_str: str | None = None) -> dict:
    date_str = date_str or today().isoformat()
    con = connect()
    con.execute("""CREATE TABLE IF NOT EXISTS content_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, content_date TEXT NOT NULL,
        content_type TEXT NOT NULL, platform TEXT NOT NULL, seq INTEGER NOT NULL,
        title TEXT NOT NULL, body TEXT NOT NULL, primary_keyword TEXT,
        secondary_keywords TEXT, hashtags TEXT, slug TEXT, meta_description TEXT,
        cta TEXT, audience TEXT, seo_score INTEGER DEFAULT 0,
        status TEXT DEFAULT 'suggested', file_path TEXT,
        created_by TEXT DEFAULT 'ai_agent',
        created_at TEXT DEFAULT (datetime('now','localtime')),
        UNIQUE(content_date, content_type, platform, seq))""")
    rows = build_content(date_str)
    created = rescored = 0
    for r in rows:
        existed = con.execute(
            "SELECT 1 FROM content_items WHERE content_date=? AND "
            "content_type=? AND platform=? AND seq=?",
            (r["content_date"], r["content_type"], r["platform"],
             r["seq"])).fetchone()
        con.execute(
            """INSERT INTO content_items
               (content_date,content_type,platform,seq,title,body,
                primary_keyword,secondary_keywords,hashtags,slug,
                meta_description,cta,audience,seo_score)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
               ON CONFLICT(content_date,content_type,platform,seq) DO UPDATE SET
                 title=excluded.title, body=excluded.body,
                 primary_keyword=excluded.primary_keyword,
                 secondary_keywords=excluded.secondary_keywords,
                 hashtags=excluded.hashtags, slug=excluded.slug,
                 meta_description=excluded.meta_description,
                 cta=excluded.cta, audience=excluded.audience,
                 seo_score=excluded.seo_score""",
            (r["content_date"], r["content_type"], r["platform"], r["seq"],
             r["title"], r["body"], r["primary_keyword"],
             r["secondary_keywords"], r["hashtags"], r["slug"],
             r["meta_description"], r["cta"], r["audience"], r["seo_score"]))
        if existed:
            rescored += 1
        else:
            created += 1
    con.commit()
    con.close()
    return {"date": date_str, "created": created, "existing": rescored,
            "total": len(rows)}


def _seed_ad_models(con) -> None:
    for code, name, fam, basis, rate, plats, desc in AD_MODELS:
        con.execute(
            """INSERT INTO ad_models
               (model_code,model_name,model_family,payout_basis,typical_rate,
                platforms,description)
               VALUES (?,?,?,?,?,?,?)
               ON CONFLICT(model_code) DO UPDATE SET
                 model_name=excluded.model_name, model_family=excluded.model_family,
                 payout_basis=excluded.payout_basis, typical_rate=excluded.typical_rate,
                 platforms=excluded.platforms, description=excluded.description""",
            (code, name, fam, basis, rate, plats, desc))


def _seed_ad_features(con) -> None:
    ids = {r["model_code"]: r["id"]
           for r in con.execute("SELECT id, model_code FROM ad_models")}
    for name, kind, plat, place, fmt, size, code, share, desc in AD_FEATURES:
        con.execute(
            """INSERT INTO ad_features
               (feature_name,feature_kind,platform,placement,format_spec,size,
                model_id,revenue_share,description)
               VALUES (?,?,?,?,?,?,?,?,?)
               ON CONFLICT(feature_name) DO UPDATE SET
                 feature_kind=excluded.feature_kind, platform=excluded.platform,
                 placement=excluded.placement, format_spec=excluded.format_spec,
                 size=excluded.size, model_id=excluded.model_id,
                 revenue_share=excluded.revenue_share,
                 description=excluded.description""",
            (name, kind, plat, place, fmt, size, ids.get(code), share, desc))


# ══════════════════════════════════════════════════════════
# AI AGENT TASKS
# ══════════════════════════════════════════════════════════
def _active_block(hhmm: str) -> int:
    if hhmm < "06:00":
        return 4
    if hhmm < "12:00":
        return 1
    if hhmm < "18:00":
        return 2
    return 3


AI_TASKS = [
    ("AI Digest: Review & Select Today's 5 Social Media Posts",
     "Select today's Facebook, YouTube and LinkedIn posts from the digest "
     "email. 5 SEO-friendly posts per platform (15 total) are pre-written with "
     "keyword, hashtags, CTA and SEO score. Pick 1+ per platform to publish.",
     "high", "marketing", 1.5),
    ("AI Digest: Select Today's 5 SEO Blog Titles",
     "Choose one of the 5 SEO-friendly blog titles to publish today. Each "
     "includes target keyword, meta description (120-160 chars), URL slug, "
     "H2 outline, word target and internal-link plan for promoting services.",
     "high", "marketing", 2.0),
    ("AI Digest: Select Today's 5 SEO Sales Emails",
     "Pick the sales email to send today from the 5 SEO-friendly drafts. Each "
     "has subject line, preview text, body copy and CTA, mapped to a service "
     "line and audience segment to increase service sales.",
     "high", "sales", 1.0),
    ("AI Task: Online Ads DISPLAY Setup for Today's Content",
     "Configure today's ad display placements across Facebook, YouTube, "
     "LinkedIn and Blog (14 features: feed banners, carousels, pre-roll, "
     "overlay, sponsored content, above-fold/in-article/sticky blog slots). "
     "Sizes and formats listed in ad_features.",
     "medium", "marketing", 1.0),
    ("AI Task: Online Ads EARNING Model Optimisation",
     "Review today's earning models (CPC/CPM/CPA/CPV/AFFILIATE/SPONSORED/"
     "PROGRAMMATIC) and reallocate to the highest eCPM placements. Track "
     "revenue share per platform and pause negative-ROAS sets.",
     "medium", "operations", 1.0),
]


def create_tasks(date_str: str | None = None, hhmm: str | None = None) -> dict:
    d = date_str or today().isoformat()
    now = datetime.datetime.now(BST)
    hhmm = hhmm or now.strftime("%H:%M")
    block = _active_block(hhmm)
    con = connect()
    con.execute("""CREATE TABLE IF NOT EXISTS daily_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, task_title TEXT NOT NULL,
        task_description TEXT, priority TEXT DEFAULT 'medium',
        status TEXT DEFAULT 'pending', category TEXT DEFAULT 'general',
        assignee TEXT DEFAULT 'ceo', due_date TEXT, estimated_hours REAL DEFAULT 1.0,
        actual_hours REAL DEFAULT 0.0, bst_block_id INTEGER,
        related_lead_id INTEGER, related_ticket_id INTEGER,
        created_by TEXT DEFAULT 'ai_agent',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME)""")

    created, existing = [], []
    for title, desc, prio, cat, hrs in AI_TASKS:
        due = f"{d} {hhmm}"
        row = con.execute(
            "SELECT id FROM daily_tasks WHERE task_title=? AND due_date=?",
            (title, due)).fetchone()
        if row:
            existing.append({"id": row["id"], "title": title})
            continue
        cur = con.execute(
            """INSERT INTO daily_tasks
               (task_title,task_description,priority,status,category,assignee,
                due_date,estimated_hours,bst_block_id,created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)""",
            (title, desc, prio, "pending", cat, "ai_agent", due, hrs,
             block, "ai_agent"))
        tid = cur.lastrowid
        created.append({"id": tid, "title": title})
        con.execute(
            """INSERT INTO task_logs (task_id,action,performed_by,details)
               VALUES (?,?,?,?)""",
            (tid, "assigned", "ai_agent",
             f"Assigned to AI Agent for {d} (Block {block}) via content digest"))
    con.commit()
    con.close()
    return {"date": d, "block": block, "created": created, "existing": existing}


# ══════════════════════════════════════════════════════════
# DAILY FILES
# ══════════════════════════════════════════════════════════
def write_files(date_str: str | None = None) -> list[str]:
    d = date_str or today().isoformat()
    day_dir = CONTENT_DIR / d
    day_dir.mkdir(parents=True, exist_ok=True)
    con = connect()
    rows = [dict(r) for r in con.execute(
        "SELECT * FROM content_items WHERE content_date=? "
        "ORDER BY content_type, platform, seq", (d,))]
    ads_models = [dict(r) for r in con.execute(
        "SELECT * FROM ad_models ORDER BY model_family, model_code")]
    ads_features = [dict(r) for r in con.execute(
        "SELECT f.*, m.model_code FROM ad_features f "
        "LEFT JOIN ad_models m ON m.id=f.model_id "
        "ORDER BY f.feature_kind, f.platform")]
    con.close()

    written: list[str] = []

    # JSON bundle (machine readable)
    p = day_dir / "content_bundle.json"
    p.write_text(json.dumps(
        {"date": d, "items": rows, "ad_models": ads_models,
         "ad_features": ads_features}, indent=2, ensure_ascii=False),
        encoding="utf-8")
    written.append(str(p))

    # Social markdown (Facebook / YouTube / LinkedIn)
    p = day_dir / "social_posts.md"
    parts = [f"# Social Media Posts — {d} (Asia/Dhaka)\n"]
    for plat in PLATFORMS:
        parts.append(f"\n## {plat.title()}\n")
        for r in [x for x in rows if x["platform"] == plat]:
            parts.append(
                f"### {r['seq']}. {r['title']}\n"
                f"- SEO score: **{r['seo_score']}**/100 | "
                f"keyword: `{r['primary_keyword']}`\n"
                f"- Hashtags: {r['hashtags']}\n"
                f"- CTA: {r['cta']}\n\n"
                f"{r['body']}\n\n---\n")
    p.write_text("\n".join(parts), encoding="utf-8")
    written.append(str(p))

    # Blog titles markdown
    p = day_dir / "blog_titles.md"
    parts = [f"# SEO Blog Titles — {d}\n"]
    for r in [x for x in rows if x["content_type"] == "blog"]:
        parts.append(
            f"## {r['seq']}. {r['title']}\n"
            f"- SEO score: **{r['seo_score']}**/100\n"
            f"- Keyword: `{r['primary_keyword']}`\n"
            f"- Slug: `{r['slug']}`\n"
            f"- Meta ({len(r['meta_description'] or '')} chars): "
            f"{r['meta_description']}\n\n{r['body']}\n\n---\n")
    p.write_text("\n".join(parts), encoding="utf-8")
    written.append(str(p))

    # Sales emails markdown
    p = day_dir / "sales_emails.md"
    parts = [f"# SEO Sales Emails — {d}\n"]
    for r in [x for x in rows if x["content_type"] == "email"]:
        parts.append(
            f"## {r['seq']}. {r['title']}\n"
            f"- SEO score: **{r['seo_score']}**/100 | audience: {r['audience']}\n"
            f"- Slug: `{r['slug']}` | CTA: {r['cta']}\n\n{r['body']}\n\n---\n")
    p.write_text("\n".join(parts), encoding="utf-8")
    written.append(str(p))

    # Ads markdown (display + earning)
    p = day_dir / "online_ads.md"
    parts = [f"# Online Ads — Display & Earning — {d}\n",
             "\n## EARNING MODELS\n"]
    for m in ads_models:
        if m["model_family"] == "earning":
            parts.append(
                f"- **{m['model_code']}** — {m['model_name']} | "
                f"{m['payout_basis']} | ~${m['typical_rate']:.2f} | "
                f"platforms: {m['platforms']}\n  {m['description']}\n")
    parts.append("\n## BUYING MODELS\n")
    for m in ads_models:
        if m["model_family"] == "buying":
            parts.append(
                f"- **{m['model_code']}** — {m['model_name']} | "
                f"{m['payout_basis']} | ~${m['typical_rate']:.2f}\n"
                f"  {m['description']}\n")
    for kind in ("display", "earning"):
        parts.append(f"\n## {kind.upper()} PLACEMENTS\n")
        for f in [x for x in ads_features if x["feature_kind"] == kind]:
            parts.append(
                f"- **{f['feature_name']}** ({f['platform']}) — "
                f"{f['placement']} | {f['format_spec']} | {f['size']} | "
                f"model {f['model_code'] or '-'} | share "
                f"{(f['revenue_share'] or 0)*100:.0f}%\n  {f['description']}\n")
    p.write_text("\n".join(parts), encoding="utf-8")
    written.append(str(p))

    # update file_path on content rows
    con = connect()
    for label, fname in (("social", "social_posts.md"), ("blog", "blog_titles.md"),
                         ("email", "sales_emails.md")):
        con.execute("UPDATE content_items SET file_path=? "
                    "WHERE content_date=? AND content_type=?",
                    (str(day_dir / fname), d, label))
    con.commit()
    con.close()
    return written


# ══════════════════════════════════════════════════════════
# EMAIL DIGEST  (select-today's-task links)
# ══════════════════════════════════════════════════════════
def _sel(kind: str, cid: int) -> str:
    """Deep link that marks a content item as SELECTED for publication."""
    return (f"{CRM_URL}?action=select_content"
            f"&kind={kind}&id={cid}&date={today().isoformat()}")


def _task_sel(tid: int) -> str:
    return f"{CRM_URL}?action=accept_task&id={tid}&assignee=ai_agent"


def build_email(date_str: str | None = None) -> str:
    d = date_str or today().isoformat()
    con = connect()
    rows = [dict(r) for r in con.execute(
        "SELECT * FROM content_items WHERE content_date=? "
        "ORDER BY CASE content_type WHEN 'social' THEN 1 WHEN 'blog' THEN 2 "
        "ELSE 3 END, platform, seq", (d,))]
    tasks = [dict(r) for r in con.execute(
        "SELECT id,task_title,task_description,priority,category "
        "FROM daily_tasks WHERE assignee='ai_agent' AND due_date LIKE ? "
        "ORDER BY id", (d + "%",))]
    ad_f = [dict(r) for r in con.execute(
        "SELECT f.*, m.model_code FROM ad_features f "
        "LEFT JOIN ad_models m ON m.id=f.model_id "
        "ORDER BY f.feature_kind, f.platform, f.feature_name")]
    ad_m = [dict(r) for r in con.execute(
        "SELECT * FROM ad_models WHERE model_family='earning' ORDER BY model_code")]
    con.close()

    E = html.escape

    def section_social():
        out = []
        for plat in PLATFORMS:
            items = [r for r in rows if r["platform"] == plat]
            out.append(f'<h3 class="plat">{plat.title()} &mdash; '
                       f'{len(items)} suggestions</h3>')
            for r in items:
                out.append(f"""
<div class="card">
  <div class="card-hd">
    <span class="num">{r['seq']}</span>
    <span class="score">{r['seo_score']}/100</span>
  </div>
  <div class="ttl">{E(r['title'])}</div>
  <div class="kw">keyword: <code>{E(r['primary_keyword'] or '')}</code></div>
  <div class="body">{E(_lead(r['body'], 220))}</div>
  <div class="tags">{E(r['hashtags'] or '')}</div>
  <div class="cta">CTA: {E(r['cta'] or '')}</div>
  <a class="btn" href="{_sel('social', r['id'])}">✅ Select this post</a>
</div>""")
        return "".join(out)

    def section_blogs():
        out = []
        for r in [x for x in rows if x["content_type"] == "blog"]:
            out.append(f"""
<div class="card">
  <div class="card-hd">
    <span class="num">{r['seq']}</span>
    <span class="score">{r['seo_score']}/100</span>
  </div>
  <div class="ttl">{E(r['title'])}</div>
  <div class="kw">keyword: <code>{E(r['primary_keyword'] or '')}</code>
     &middot; slug: <code>{E(r['slug'] or '')}</code>
     &middot; meta {len(r['meta_description'] or '')} chars</div>
  <div class="body">{E(r['meta_description'] or '')}</div>
  <a class="btn" href="{_sel('blog', r['id'])}">✅ Publish this title</a>
</div>""")
        return "".join(out)

    def section_emails():
        out = []
        for r in [x for x in rows if x["content_type"] == "email"]:
            out.append(f"""
<div class="card">
  <div class="card-hd">
    <span class="num">{r['seq']}</span>
    <span class="score">{r['seo_score']}/100</span>
    <span class="aud">{E(r['audience'] or '')}</span>
  </div>
  <div class="ttl">{E(r['title'])}</div>
  <div class="body">{E(_lead(r['body'], 200))}</div>
  <div class="cta">CTA: {E(r['cta'] or '')}</div>
  <a class="btn" href="{_sel('email', r['id'])}">✅ Send this email</a>
</div>""")
        return "".join(out)

    def section_tasks():
        if not tasks:
            return "<p class='muted'>No AI-agent tasks assigned yet.</p>"
        out = []
        for t in tasks:
            out.append(f"""
<div class="card task">
  <div class="card-hd">
    <span class="prio {E(t['priority'])}">{E(t['priority'].upper())}</span>
    <span class="cat">{E(t['category'])}</span>
    <span class="aid">#{t['id']}</span>
  </div>
  <div class="ttl">{E(t['task_title'])}</div>
  <div class="body">{E(_lead(t['task_description'] or '', 240))}</div>
  <a class="btn" href="{_task_sel(t['id'])}">👉 Select this task</a>
  <a class="btn ghost" href="{CRM_URL}?action=task_detail&id={t['id']}">View detail</a>
</div>""")
        return "".join(out)

    def section_ads():
        out = ['<h3>💰 Online Ads &mdash; EARNING models</h3><div class="grid">']
        for m in ad_m:
            out.append(f"""
<div class="card ad">
  <div class="ttl">{E(m['model_code'])} &middot; {E(m['model_name'])}</div>
  <div class="kw">{E(m['payout_basis'] or '')} &middot; ~${m['typical_rate']:.2f}</div>
  <div class="body">{E(_lead(m['description'] or '', 160))}</div>
</div>""")
        out.append("</div><h3>🖥️ Online Ads &mdash; DISPLAY placements</h3>"
                   "<div class='grid'>")
        for f in ad_f:
            if f["feature_kind"] != "display":
                continue
            out.append(f"""
<div class="card ad">
  <div class="card-hd">
    <span class="plat-tag">{E(f['platform'])}</span>
    <span class="model">{E(f['model_code'] or '-')}</span>
  </div>
  <div class="ttl">{E(f['feature_name'])}</div>
  <div class="kw">{E(f['format_spec'] or '')} &middot; {E(f['size'] or '')}
     &middot; {int((f['revenue_share'] or 0)*100)}% share</div>
  <div class="body">{E(_lead(f['description'] or '', 150))}</div>
</div>""")
        out.append("</div>")
        return "".join(out)

    n_social = len([r for r in rows if r["content_type"] == "social"])
    n_blog = len([r for r in rows if r["content_type"] == "blog"])
    n_email = len([r for r in rows if r["content_type"] == "email"])
    ts = datetime.datetime.now(BST).strftime("%Y-%m-%d %H:%M BST")
    cur_hhmm = datetime.datetime.now(BST).strftime("%H:%M")

    doc = f"""<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SCITBD Daily Task Digest {d}</title>
<style>
 body{{margin:0;background:#0b1020;color:#e8ecf8;
      font:15px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif}}
 .wrap{{max-width:760px;margin:0 auto;padding:24px 16px 64px}}
 .hd{{background:linear-gradient(135deg,#1b2a6b,#7b2ff7);
      border-radius:16px;padding:26px 24px;margin-bottom:24px}}
 h1{{margin:0 0 6px;font-size:23px;color:#fff}}
 .sub{{opacity:.9;font-size:13px}}
 .stats{{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}}
 .stat{{background:rgba(255,255,255,.16);border-radius:9px;
        padding:8px 12px;font-size:13px}}
 h2{{font-size:18px;margin:30px 0 6px;color:#ffd166;
     border-bottom:1px solid #26304f;padding-bottom:7px}}
 h3.plat{{font-size:15px;margin:20px 0 8px;color:#8fd3ff}}
 h3{{font-size:15px;margin:20px 0 8px;color:#8fd3ff}}
 .card{{background:#141a33;border:1px solid #26304f;border-radius:12px;
        padding:15px 16px;margin-bottom:12px}}
 .card.task{{border-left:4px solid #ffd166}}
 .card.ad{{background:#101728}}
 .card-hd{{display:flex;gap:9px;align-items:center;flex-wrap:wrap;
           margin-bottom:7px}}
 .num{{background:#7b2ff7;color:#fff;border-radius:50%;width:23px;height:23px;
       display:inline-flex;align-items:center;justify-content:center;
       font-size:12px;font-weight:700}}
 .score{{background:#0f3d2e;color:#4ade80;border-radius:20px;
         padding:2px 9px;font-size:11.5px;font-weight:700}}
 .aud{{background:#1e293b;color:#94a3b8;border-radius:20px;
       padding:2px 9px;font-size:11.5px}}
 .prio{{border-radius:20px;padding:2px 9px;font-size:11px;font-weight:700}}
 .prio.critical{{background:#4c0519;color:#fb7185}}
 .prio.high{{background:#422006;color:#fbbf24}}
 .prio.medium{{background:#1e3a5f;color:#7dd3fc}}
 .cat,.aid,.plat-tag,.model{{background:#1e293b;color:#94a3b8;
      border-radius:20px;padding:2px 9px;font-size:11.5px}}
 .plat-tag{{background:#0c4a6e;color:#7dd3fc}}
 .model{{background:#052e16;color:#4ade80}}
 .ttl{{font-size:15.5px;font-weight:700;margin-bottom:6px;color:#fff}}
 .kw{{font-size:12.5px;color:#a5b4cb;margin-bottom:7px}}
 code{{background:#0b1020;padding:1px 6px;border-radius:5px;color:#8fd3ff;
       font-size:12px}}
 .body{{font-size:13.5px;color:#c3cbdf;white-space:pre-wrap;margin-bottom:8px}}
 .tags{{font-size:12.5px;color:#7b2ff7;margin-bottom:6px}}
 .cta{{font-size:12.5px;color:#ffd166;margin-bottom:9px}}
 .btn{{display:inline-block;background:#7b2ff7;color:#fff!important;
       text-decoration:none;padding:8px 15px;border-radius:8px;
       font-size:13.5px;font-weight:600;margin:3px 6px 3px 0}}
 .btn:hover{{background:#9147ff}}
 .btn.ghost{{background:transparent;border:1px solid #33406b;
             color:#a5b4cb!important}}
 .grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(228px,1fr));
        gap:11px}}
 .note{{background:#141a33;border:1px solid #26304f;border-radius:11px;
        padding:14px 16px;font-size:13.5px;color:#a5b4cb;margin-top:14px}}
 .muted{{color:#64748b;font-size:13.5px}}
 footer{{margin-top:34px;font-size:12px;color:#475569;text-align:center}}
</style></head><body><div class="wrap">

<div class="hd">
  <h1>&#128197; SCITBD Daily Task Digest</h1>
  <div class="sub">{d} &middot; {ts} &middot; Block {_active_block(cur_hhmm)} active</div>
  <div class="stats">
    <div class="stat">&#128241; {n_social} social posts</div>
    <div class="stat">&#128221; {n_blog} blog titles</div>
    <div class="stat">&#9993;&#65039; {n_email} sales emails</div>
    <div class="stat">&#129302; {len(tasks)} AI-agent tasks</div>
    <div class="stat">&#128421;&#65039; {len(ad_f)} ad placements</div>
  </div>
</div>

<h2>&#128073; Select Today's Task</h2>
<p class="muted">Tap a button to claim it &mdash; the AI Agent executes on selection.</p>
{section_tasks()}

<h2>&#128241; Social Media Posts <span class="muted">(5 per platform, SEO-scored)</span></h2>
{section_social()}

<h2>&#128221; Today's Blogs <span class="muted">(5 SEO-friendly titles)</span></h2>
{section_blogs()}

<h2>&#9993;&#65039; Today's Emails <span class="muted">(5 SEO-friendly sales emails)</span></h2>
{section_emails()}

<h2>&#128421;&#65039;&#128176; Online Ads &mdash; Display &amp; Earning</h2>
{section_ads()}

<div class="note">
  <strong>How it works:</strong> each <em>&ldquo;Select&rdquo;</em> button
  deep-links back to the CRM and flips the item to <em>selected</em>, then the
  AI Agent begins drafting/publishing. Selections sync into
  <code>daily_tasks</code> so the 24-hour BST workflow picks them up in the
  next block.
</div>

<footer>SCITBD AI CEO &middot; generated {ts} &middot; Asia/Dhaka (GMT+6)<br>
Source files: <code>content/{d}/</code></footer>
</div></body></html>"""

    EMAIL_DIR.mkdir(parents=True, exist_ok=True)
    out = EMAIL_DIR / f"task_digest_{d}.html"
    out.write_text(doc, encoding="utf-8")

    # plain-text companion for SMTP forwarding / quick scanning
    txt = [f"SCITBD DAILY TASK DIGEST - {d} ({ts})", "=" * 60, "",
           "SELECT TODAY'S TASK:"]
    for t in tasks:
        txt.append(f"  [{t['id']}] {t['task_title']}")
        txt.append(f"        {_task_sel(t['id'])}")
    for label, ctype in (("SOCIAL POSTS (5 per platform)", "social"),
                         ("BLOG TITLES (5)", "blog"),
                         ("SALES EMAILS (5)", "email")):
        txt += ["", f"{label}:"]
        for r in [x for x in rows if x["content_type"] == ctype]:
            txt.append(f"  {r['seq']}. [{r['seo_score']}] {r['title']}")
            txt.append(f"        {_sel(r['platform'], r['id'])}")
    txt += ["", f"Files: content/{d}/"]
    (EMAIL_DIR / f"task_digest_{d}.txt").write_text("\n".join(txt),
                                                    encoding="utf-8")
    return str(out)


def send_email_note(date_str: str | None = None) -> dict:
    """
    Mail transport hook.

    Uses SMTP when SCITBD_SMTP_* env vars are set; otherwise writes the digest
    files and records a PENDING ops-log row so nothing is silently dropped.
    """
    d = date_str or today().isoformat()
    path = build_email(d)
    host = os.environ.get("SCITBD_SMTP_HOST")
    port = int(os.environ.get("SCITBD_SMTP_PORT", "587"))
    user = os.environ.get("SCITBD_SMTP_USER")
    pw = os.environ.get("SCITBD_SMTP_PASS")

    if not (host and user and pw):
        _ops_log(f"Digest for {d} written to {path} - SMTP not configured "
                 f"(set SCITBD_SMTP_HOST/USER/PASS to enable sending)",
                 "PENDING")
        return {"sent": False, "reason": "smtp_not_configured", "path": path}

    import smtplib
    from email.mime.multipart import MIMEMultipart
    from email.mime.text import MIMEText
    msg = MIMEMultipart("alternative")
    msg["Subject"] = f"SCITBD Daily Task Digest - {d} (select your task)"
    msg["From"] = user
    msg["To"] = CEO_EMAIL
    body = pathlib.Path(path).read_text(encoding="utf-8")
    msg.attach(MIMEText(
        f"Open the digest: {path}\n\nHTML version attached.", "plain"))
    msg.attach(MIMEText(body, "html"))
    with smtplib.SMTP(host, port, timeout=15) as s:
        s.starttls()
        s.login(user, pw)
        s.send_message(msg)
    _ops_log(f"Digest email for {d} sent to {CEO_EMAIL}", "SUCCESS")
    return {"sent": True, "to": CEO_EMAIL, "path": path}


def _ops_log(action: str, status: str = "SUCCESS") -> None:
    try:
        con = connect()
        con.execute("""CREATE TABLE IF NOT EXISTS operational_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, block_id INTEGER,
            block_name TEXT NOT NULL, action_taken TEXT NOT NULL,
            status TEXT DEFAULT 'SUCCESS',
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP)""")
        con.execute(
            "INSERT INTO operational_logs (block_name, action_taken, status) "
            "VALUES (?,?,?)", ("Content Engine", action, status))
        con.commit()
        con.close()
    except Exception:
        pass


# ══════════════════════════════════════════════════════════
# T-30 MINUTE PRE-WORKFLOW TRIGGER
# ══════════════════════════════════════════════════════════
T_MINUS_MIN = 30


def _to_min(hhmm: str) -> int:
    h, m = map(int, hhmm.split(":"))
    return h * 60 + m


def find_t_minus(hhmm: str) -> tuple[str | None, int | None]:
    """
    Is `hhmm` exactly T-30 before a block start?

    Block starts  : 00:00, 06:00, 12:00, 18:00 (BST)
    T-30 triggers : 23:30, 05:30, 11:30, 17:30
    Returns (block_start, minutes_to_start) or (None, None).
    """
    now_min = _to_min(hhmm)
    for start in BLOCK_STARTS:
        trigger = (_to_min(start) - T_MINUS_MIN) % (24 * 60)
        if now_min == trigger:
            return start, T_MINUS_MIN
    return None, None


def _t30_times() -> list[str]:
    out = []
    for s in BLOCK_STARTS:
        t = (_to_min(s) - T_MINUS_MIN) % (24 * 60)
        out.append(f"{t // 60:02d}:{t % 60:02d}")
    return out


def t_minus_check(date_str: str | None = None,
                  dry: bool | None = None) -> str:
    """
    T-30 pre-workflow hook: 30 minutes before a block starts, prepare today's
    content, assign the AI-agent tasks and build the digest email so it is
    waiting in the inbox before the workflow opens.
    """
    now = datetime.datetime.now(BST)
    hhmm = now.strftime("%H:%M")
    d = date_str or now.date().isoformat()

    start, mins = find_t_minus(hhmm)
    if start is None:
        return (f"T-30: no trigger at {hhmm} BST "
                f"(triggers: {', '.join(_t30_times())})")

    g = generate(d)
    t = create_tasks(d, hhmm)
    f = write_files(d)

    if dry:
        return (f"T-30 dry-run for block {start} (+{mins}min): "
                f"content={g['created']} new/{g['existing']} kept, "
                f"tasks={len(t['created'])} created, files={len(f)}")

    e = send_email_note(d)
    _ops_log(f"T-30 pre-workflow fired at {hhmm} BST for block {start}: "
             f"{g['created']} content rows, {len(t['created'])} AI tasks, "
             f"{len(f)} files, email_sent={e.get('sent')}")
    return (f"OK T-30 @{hhmm} BST -> block {start} in {mins}min | "
            f"content +{g['created']}, tasks +{len(t['created'])}, "
            f"files {len(f)}, email {'sent' if e.get('sent') else 'file-only'}")


def preview(date_str: str | None = None) -> str:
    d = date_str or today().isoformat()
    con = connect()
    rows = [dict(r) for r in con.execute(
        "SELECT * FROM content_items WHERE content_date=? "
        "ORDER BY content_type, platform, seq", (d,))]
    con.close()
    if not rows:
        return (f"No content for {d}. "
                f"Run: python scitbd_content_engine.py generate")
    L = [f"SCITBD CONTENT PREVIEW - {d}", "=" * 62]
    for ct, label in (("social", "SOCIAL MEDIA POSTS (5 per platform)"),
                      ("blog", "SEO BLOG TITLES (5)"),
                      ("email", "SEO SALES EMAILS (5)")):
        subset = [r for r in rows if r["content_type"] == ct]
        L += ["", label, "-" * 62]
        for r in subset:
            L.append(f" {r['seq']}. {r['title']} [{r['platform']}]")
            L.append(f"     SEO {r['seo_score']}/100 | kw: "
                     f"{r['primary_keyword']}")
            L.append(f"     {CRM_URL}?action=select_content"
                     f"&kind={ct}&id={r['id']}")
    L += ["", "=" * 62, f"total items: {len(rows)}"]
    return "\n".join(L)


# ══════════════════════════════════════════════════════════
# CLI
# ══════════════════════════════════════════════════════════
def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "all"
    arg = sys.argv[2] if len(sys.argv) > 2 else None

    if cmd == "install":
        install()
    elif cmd == "generate":
        print("generate:", generate(arg))
    elif cmd == "tasks":
        r = create_tasks(arg)
        print(f"tasks: {len(r['created'])} created, "
              f"{len(r['existing'])} already present")
        for t in r["created"]:
            print(f"   + #{t['id']} {t['title']}")
    elif cmd == "files":
        for p in write_files(arg):
            print("   wrote", p)
    elif cmd == "email":
        print("email:", send_email_note(arg))
    elif cmd == "preview":
        print(preview(arg))
    elif cmd == "tminus":
        print(t_minus_check(arg))
    elif cmd == "all":
        d = arg or today().isoformat()
        install()
        print("generate:", generate(d))
        t = create_tasks(d)
        print(f"tasks    : {len(t['created'])} created, "
              f"{len(t['existing'])} existing (assignee=ai_agent)")
        for x in t["created"]:
            print(f"   + #{x['id']} {x['title']}")
        for p in write_files(d):
            print("   file  :", p)
        print("email    :", send_email_note(d))
        print("preview  :")
        print(preview(d))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
