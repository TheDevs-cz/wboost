<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * The three PDF files a calendar can be exported as
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

    /**
     * „Pro tisk (CMYK)“: the pages of {@see Print}, every colour converted to
     * DeviceCMYK for offset print (output intent Coated FOGRA39, PDF/X-4) —
     * black text in black ink only, brand colours in the palette's CMYK,
     * photos through the ICC profile, crop marks in the registration colour
     * ({@see \WBoost\Web\Services\Print\Cmyk\PdfCmykConverter}).
     */
    case PrintCmyk = 'print_cmyk';

    public function label(): string
    {
        return match ($this) {
            self::Print => 'Pro tisk',
            self::Approval => 'Náhled k odsouhlasení',
            self::PrintCmyk => 'Pro tisk (CMYK)',
        };
    }

    /** A file for the print shop: print settings applied, uploads at full size, nothing invented drawn. */
    public function isPrint(): bool
    {
        return $this !== self::Approval;
    }

    public function isCmyk(): bool
    {
        return $this === self::PrintCmyk;
    }

    /** The last part of the download name: `Kalendář 2027 - tisk.pdf`. */
    public function fileSuffix(): string
    {
        return match ($this) {
            self::Print => 'tisk',
            self::Approval => 'náhled',
            self::PrintCmyk => 'tisk CMYK',
        };
    }

    public function pictures(): CalendarPictureSource
    {
        return match ($this) {
            self::Print, self::PrintCmyk => CalendarPictureSource::Original,
            self::Approval => CalendarPictureSource::PreviewJpeg,
        };
    }
}
