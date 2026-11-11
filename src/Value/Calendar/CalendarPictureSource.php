<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * Which file of an uploaded picture a headless render is given
 * ({@see \WBoost\Web\Services\Calendar\CalendarRenderAssets}).
 */
enum CalendarPictureSource
{
    /** The preview derivative (WebP, long edge ≤ 1600 px) — for a screenshot. */
    case Preview;

    /**
     * The preview as a JPEG — for a PDF that has to stay small. Chromium's
     * PDF backend copies JPEG data into the file as it is, but stores every
     * other format losslessly: a 1600 px WebP preview of ~300 kB would take
     * 3–4 MB in the PDF.
     */
    case PreviewJpeg;

    /** The uploaded file, byte for byte — for print. */
    case Original;
}
