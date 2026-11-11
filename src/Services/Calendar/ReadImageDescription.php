<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Calendar;

/**
 * The caption a photographer embedded in a picture — offered as the photo's
 * caption after a bulk upload. Read from the ORIGINAL upload bytes (the
 * normalisation strips metadata): EXIF `ImageDescription` first, else the
 * first `<rdf:li>` of XMP `dc:description`. Never throws: no caption is the
 * normal case.
 */
readonly final class ReadImageDescription
{
    private const int MAX_LENGTH = 255;

    public function read(string $contents): null|string
    {
        return self::clean($this->exif($contents)) ?? self::clean(self::xmp($contents));
    }

    private function exif(string $contents): null|string
    {
        try {
            $image = new \Imagick();
            // ping = headers and profiles only, no pixel decode.
            $image->pingImageBlob($contents);
            /** @var string|false $description false when the picture has no such property */
            $description = $image->getImageProperty('exif:ImageDescription');
            $image->clear();
        } catch (\ImagickException) {
            return null;
        }

        return is_string($description) ? $description : null;
    }

    private static function xmp(string $contents): null|string
    {
        if (preg_match('~<dc:description[^>]*>(.*?)</dc:description>~s', $contents, $description) !== 1) {
            return null;
        }

        if (preg_match('~<rdf:li[^>]*>(.*?)</rdf:li>~s', $description[1], $item) !== 1) {
            return null;
        }

        return html_entity_decode(strip_tags($item[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function clean(null|string $text): null|string
    {
        if ($text === null) {
            return null;
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            // Old cameras and editors write the local 8-bit code page.
            $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $text);

            if ($converted === false) {
                return null;
            }

            $text = $converted;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text)));

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_LENGTH);
    }
}
