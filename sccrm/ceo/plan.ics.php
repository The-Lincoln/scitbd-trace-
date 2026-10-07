<?php
// iCalendar export of the SCITBD AI CEO Daily Operational Plan.
// Daily recurring events in Asia/Dhaka -> bulk-import into Google Calendar / Outlook.
require_once __DIR__ . '/operational_plan.php';

function icsEscape($s) {
    return addcslashes(preg_replace('/\s+/', ' ', (string)$s), ",;\\");
}

// BST (UTC+6, no DST): convert wall-clock to UTC for Zulu timestamps.
function bstToUtc($hm) {
    [$h, $m] = array_map('intval', explode(':', $hm));
    $dt = new DateTime('2026-01-01 ' . sprintf('%02d:%02d:00', $h, $m), new DateTimeZone('Asia/Dhaka'));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Ymd\THis\Z');
}

$stamp = gmdate('Ymd\THis\Z');
$lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//SCITBD//CEO Operational Plan//EN',
    'X-WR-CALNAME:SCITBD CEO Daily Operational Plan (BST)', 'X-WR-TIMEZONE:Asia/Dhaka'];
foreach (ceoOperationalPlan() as $i => $slot) {
    // Overnight slot 23:30-00:00 ends next day; RRULE daily keeps it aligned.
    $endHm = $slot['end'] === '00:00' ? '24:00' : $slot['end'];
    if ($endHm === '24:00') {
        $dtEnd = new DateTime('2026-01-02 00:00:00', new DateTimeZone('Asia/Dhaka'));
        $dtEnd->setTimezone(new DateTimeZone('UTC'));
        $utcEnd = $dtEnd->format('Ymd\THis\Z');
    } else {
        $utcEnd = bstToUtc($slot['end']);
    }
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:scitbd-ops-block' . $slot['block'] . '-slot' . ($i + 1) . '@scitbd';
    $lines[] = 'DTSTAMP:' . $stamp;
    $lines[] = 'DTSTART:' . bstToUtc($slot['start']);
    $lines[] = 'DTEND:' . $utcEnd;
    $lines[] = 'RRULE:FREQ=DAILY';
    $lines[] = 'SUMMARY:' . icsEscape('[B' . $slot['block'] . ' ' . $slot['start'] . '-' . $slot['end'] . ' BST] ' . $slot['title']);
    $lines[] = 'DESCRIPTION:' . icsEscape($slot['detail'] . ' | Expected: ' . $slot['outcome']);
    $lines[] = 'CATEGORIES:' . icsEscape($slot['category'] . ',block-' . $slot['block'] . ',' . $slot['region']);
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="scitbd-ceo-operational-plan-bst.ics"');
echo implode("\r\n", $lines) . "\r\n";
