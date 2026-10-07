# -*- coding: utf-8 -*-
"""
Export the SCITBD 24-hour operational schedule from SQLite to iCalendar (.ics).
Calibrated for Asia/Dhaka (GMT+6) with daily recurrence on all 16 slots.
"""
import sqlite3, datetime, uuid, zoneinfo, pathlib

DB  = r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db"
OUT = pathlib.Path(r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_schedule.ics")

# Anchor the recurrence series on the next occurrence of each slot's weekday.
# Using a fixed far-future-safe start: today's date in BST.
BST = zoneinfo.ZoneInfo("Asia/Dhaka")
today = datetime.datetime.now(BST).date()

db = sqlite3.connect(DB)
db.row_factory = sqlite3.Row

rows = db.execute("""
    SELECT bt.*, ob.block_name, ob.regional_focus, ob.core_execution_focus
      FROM block_tasks bt
      JOIN operational_blocks ob ON ob.id = bt.block_id
     ORDER BY bt.block_id, bt.slot_start
""").fetchall()

SLA = db.execute(
    "SELECT trigger_task, sla_target, escalation_protocol FROM sla_standards ORDER BY id"
).fetchall()


def dt_local(d: datetime.date, hhmm: str) -> datetime.datetime:
    h, m = map(int, hhmm.split(":"))
    return datetime.datetime(d.year, d.month, d.day, h, m, tzinfo=BST)


def esc(text: str) -> str:
    return (text.replace("\\", "\\\\")
                .replace(";", "\\;")
                .replace(",", "\\,")
                .replace("\n", "\\n"))


def fold(line: str) -> str:
    """RFC 5545 line folding at 75 octets."""
    out, cur = [], ""
    for ch in line:
        if len((cur + ch).encode("utf-8")) > 74:
            out.append(cur)
            cur = " " + ch
        else:
            cur += ch
    out.append(cur)
    return "\r\n".join(out)


lines = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//SCITBD AI CEO//Global Operational Framework//EN",
    "CALSCALE:GREGORIAN",
    "METHOD:PUBLISH",
    "X-WR-CALNAME:SCITBD AI CEO Operational Plan",
    "X-WR-TIMEZONE:Asia/Dhaka",
    fold("X-WR-CALDESC:24-hour autonomous operational cycle across 4 regional blocks (BST)."),
    # Timezone definition so Google/Outlook resolve TZID correctly
    "BEGIN:VTIMEZONE",
    "TZID:Asia/Dhaka",
    "BEGIN:STANDARD",
    "DTSTART:19700101T000000",
    "TZOFFSETFROM:+0600",
    "TZOFFSETTO:+0600",
    "TZNAME:+06",
    "END:STANDARD",
    "END:VTIMEZONE",
]

# Map slot end back to minutes for end-date rollover across midnight
def add_minutes(hhmm: str, mins: int) -> str:
    h, m = map(int, hhmm.split(":"))
    t = h * 60 + m + mins
    return f"{(t // 60) % 24:02d}:{t % 60:02d}"

for r in rows:
    ss, se = r["slot_start"], r["slot_end"]
    start = dt_local(today, ss)
    # end may roll to next day (e.g. 23:30 -> 00:00)
    end_date = today + datetime.timedelta(days=1) if se <= ss else today
    end = dt_local(end_date, se)

    duration_min = int((end - start).total_seconds() // 60)

    desc_parts = [
        f"BLOCK: {r['block_name']}",
        f"REGION: {r['regional_focus']}",
        f"FOCUS: {r['core_execution_focus']}",
        "",
        "DETAILED TASKS:",
        r["detailed_tasks"],
        "",
        "EXPECTED OUTCOME:",
        r["expected_outcome"] or "-",
    ]
    if r["escalation_protocol"]:
        desc_parts += ["", "ESCALATION:", r["escalation_protocol"]]
    desc_parts += ["", f"PRIORITY: {r['priority'].upper()}", f"CATEGORY: {r['category']}"]

    # Build an RRULE that fires only on days where this slot is in-range.
    # All slots run daily, so a plain DAILY rule suffices.
    uid = uuid.uuid5(uuid.NAMESPACE_URL,
                     f"scitbd-block{r['block_id']}-{ss}-{r['slot_name']}").hex

    lines += [
        "BEGIN:VEVENT",
        fold(f"UID:{uid}@scitbd.ceo"),
        f"DTSTAMP:{datetime.datetime.now(datetime.timezone.utc):%Y%m%dT%H%M%SZ}",
        fold(f"DTSTART;TZID=Asia/Dhaka:{start:%Y%m%d}T{start:%H%M%S}"),
        fold(f"DTEND;TZID=Asia/Dhaka:{end:%Y%m%d}T{end:%H%M%S}"),
        "RRULE:FREQ=DAILY",
        fold(f"SUMMARY:[SCITBD Ops] B{r['block_id']} {ss}-{se} {r['slot_name']}"),
        fold(f"DESCRIPTION:{esc(chr(10).join(desc_parts))}"),
        "STATUS:CONFIRMED",
        "TRANSP:OPAQUE",
        fold(f"CATEGORIES:{r['category'].upper()},{r['priority'].upper()}"),
        "BEGIN:VALARM",
        "TRIGGER:-PT15M",
        "ACTION:DISPLAY",
        fold(f"DESCRIPTION:Starts in 15 min: {r['slot_name']}"),
        "END:VALARM",
        "END:VEVENT",
    ]

# One all-day recurring reminder listing the SLA standards
sla_desc = "\\n".join(
    f"- {s['trigger_task']}: {s['sla_target']} -> {s['escalation_protocol']}" for s in SLA
)

uid = uuid.uuid5(uuid.NAMESPACE_URL, "scitbd-sla-standards").hex
lines += [
    "BEGIN:VEVENT",
    fold(f"UID:{uid}@scitbd.ceo"),
    f"DTSTAMP:{datetime.datetime.now(datetime.timezone.utc):%Y%m%dT%H%M%SZ}",
    f"DTSTART;VALUE=DATE:{today:%Y%m%d}",
    f"DTEND;VALUE=DATE:{today + datetime.timedelta(days=1):%Y%m%d}",
    "RRULE:FREQ=DAILY",
    "SUMMARY:[SCITBD] Non-Negotiable SLA Standards",
    fold("DESCRIPTION:" + esc("Daily SLA checklist:\\n" + sla_desc)),
    "STATUS:CONFIRMED",
    "TRANSP:FREE",
    "END:VEVENT",
    "END:VCALENDAR",
]

OUT.write_bytes(("\r\n".join(lines) + "\r\n").encode("utf-8"))
db.close()

n_events = sum(1 for l in lines if l == "BEGIN:VEVENT")
print(f"Wrote {OUT}")
print(f"  VEVENT count : {n_events} (16 recurring slots + 1 SLA checklist)")
print(f"  File size    : {OUT.stat().st_size} bytes")
print(f"  Anchor date  : {today} (Asia/Dhaka)")
