<?php

declare(strict_types=1);

namespace WBoost\Web\Value\Calendar;

/**
 * What an uploaded calendar file is used for. `Image` = a header / background
 * picture, `Page` = the graphic of an uploaded special page (an image, or the
 * first page of a PDF rasterised on upload).
 */
enum CalendarAssetKind: string
{
    case Photo = 'photo';
    case Logo = 'logo';
    case Icon = 'icon';
    case Qr = 'qr';
    case Image = 'image';
    case Page = 'page';

    /**
     * A client (link holder / read-only member) uploads photos only — the one
     * kind that is always a raster (`CalendarAssetUploader` refuses an SVG as
     * a photo). No client tool needs any other kind: custom icons belong to
     * the layout, which a client cannot change.
     */
    public function clientMayUpload(): bool
    {
        return $this === self::Photo;
    }
}
