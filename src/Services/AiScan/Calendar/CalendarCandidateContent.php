<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan\Calendar;

use WBoost\Web\Services\Calendar\CalendarContentSeed;

/**
 * What a calendar CANDIDATE is drawn with instead of the calendar's content.
 *
 * A candidate's picture goes back to the scanner and from there to the AI
 * provider. The consent a scan is started with speaks of the uploaded
 * picture, what is read from it, the project's font names and its palette —
 * not of what the customer has typed and uploaded into the calendar. So the
 * sample week is drawn EMPTY of it: no event, name, comment, waste date,
 * shift, photo, caption, page picture or QR code of the calendar reaches the
 * render. What is left is the content of a calendar nobody has filled in yet
 * ({@see CalendarContentSeed::initial()}) — the state most scans meet anyway,
 * because the layout is chosen before the content — plus the sample events
 * {@see CalendarCandidate} adds, and the designer's sample texts (caption,
 * LOGO / QR placeholders) the renderer draws where nothing is.
 *
 * The LAYOUT of a candidate holds none of it either: a candidate always
 * starts from a preset's default.
 */
final class CalendarCandidateContent
{
    /**
     * Never the stored content, whatever it holds: the shape of
     * `CalendarDocument::calendar()['content']` for a new calendar.
     */
    public static function neutral(): \stdClass
    {
        return (object) ['v' => 1, 'wasteKinds' => (object) CalendarContentSeed::initial()['wasteKinds']];
    }
}
