<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * The two PDF files a calendar can be exported as
 * (docs/architecture/calendars.md § "PDF export").
 */
enum CalendarExportVariant: string
{
    /**
     * „Pro tisk“: every page with the calendar's print settings — bleed and
     * crop marks when they are on — and the photos as they were uploaded.
     * Without the row of binding holes (they are punched, not printed).
     */
    case Print = 'print';

    /**
     * „Náhled k odsouhlasení“: trimmed pages as the finished product looks
     * (binding holes drawn), photos from the preview derivatives — a file
     * small enough to e-mail.
     */
    case Approval = 'approval';

    public function label(): string
    {
        return match ($this) {
            self::Print => 'Pro tisk',
            self::Approval => 'Náhled k odsouhlasení',
        };
    }

    /** The last part of the download name: `Kalendář 2027 - tisk.pdf`. */
    public function fileSuffix(): string
    {
        return match ($this) {
            self::Print => 'tisk',
            self::Approval => 'náhled',
        };
    }

    public function pictures(): CalendarPictureSource
    {
        return match ($this) {
            self::Print => CalendarPictureSource::Original,
            self::Approval => CalendarPictureSource::PreviewJpeg,
        };
    }
}
