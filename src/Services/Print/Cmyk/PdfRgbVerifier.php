<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use WBoost\Web\Exceptions\CmykConversionFailed;
use WBoost\Web\Exceptions\PdfStructureNotSupported;

/**
 * The last word on a CMYK file: reads the FINISHED PDF from scratch and
 * fails if any RGB colour is left in it. It shares no decision with the
 * converter — it does not walk from the pages, it looks at EVERY object:
 *
 * - no object may name `/DeviceRGB`, `/CalRGB` or `/Lab`, and an
 *   `/ICCBased` colour space must point at a profile with 1 or 4 components;
 * - every content stream (page contents, form XObjects, tiling patterns,
 *   Type 3 glyphs) is lexed: no `rg` / `RG`, no `/DeviceRGB cs`, no inline
 *   image;
 * - every JPEG picture must hold as many components as its colour space
 *   says (a three-channel JPEG labelled CMYK would print garbage).
 *
 * Constant memory: pictures are never loaded, only their first bytes.
 */
final class PdfRgbVerifier
{
    private const array RGB_NAMES = ['DeviceRGB' => true, 'CalRGB' => true, 'Lab' => true];

    /**
     * @return array{objects: int, contentStreams: int, pictures: int}
     *
     * @throws CmykConversionFailed an RGB colour is left, with where
     */
    public static function assertNoRgb(string $path): array
    {
        try {
            return self::check(new PdfReader($path));
        } catch (PdfStructureNotSupported $unreadable) {
            throw new CmykConversionFailed('The CMYK file cannot be verified: ' . $unreadable->getMessage(), 0, $unreadable);
        }
    }

    /**
     * @return array{objects: int, contentStreams: int, pictures: int}
     */
    private static function check(PdfReader $reader): array
    {
        $content = [];
        $pictures = 0;
        $numbers = $reader->numbers();

        foreach ($numbers as $number) {
            $value = $reader->value($number);
            self::scan($reader, $value, $number);

            if (!$value instanceof PdfDict) {
                continue;
            }

            if ($value->name('Type') === 'Page') {
                $contents = $reader->resolve($value->get('Contents'));

                foreach (is_array($contents) ? $contents : [$contents] as $reference) {
                    if ($reference instanceof PdfRef) {
                        $content[$reference->number] = true;
                    }
                }
            }

            if ($value->name('Subtype') === 'Type3') {
                $procedures = $reader->resolve($value->get('CharProcs'));

                foreach ($procedures instanceof PdfDict ? $procedures->entries : [] as $reference) {
                    if ($reference instanceof PdfRef) {
                        $content[$reference->number] = true;
                    }
                }
            }

            if (!$reader->isStream($number)) {
                continue;
            }

            if ($value->name('Subtype') === 'Form' || $reader->resolve($value->get('PatternType')) === '1') {
                $content[$number] = true;
            }

            if ($value->name('Subtype') === 'Image') {
                ++$pictures;
                self::picture($reader, $number, $value);
            }
        }

        foreach (array_keys($content) as $number) {
            if (!$reader->has($number) || !$reader->isStream($number)) {
                continue;
            }

            self::content($reader, $number);
        }

        return ['objects' => count($numbers), 'contentStreams' => count($content), 'pictures' => $pictures];
    }

    private static function scan(PdfReader $reader, mixed $value, int $number): void
    {
        if ($value instanceof PdfName) {
            if (isset(self::RGB_NAMES[$value->value])) {
                throw new CmykConversionFailed(sprintf('RGB is left in the CMYK file: object %d names /%s.', $number, $value->value));
            }

            return;
        }

        if ($value instanceof PdfDict) {
            foreach ($value->entries as $entry) {
                self::scan($reader, $entry, $number);
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        if (($value[0] ?? null) instanceof PdfName && $value[0]->value === 'ICCBased') {
            $profile = $value[1] ?? null;
            $dictionary = $profile instanceof PdfRef && $reader->has($profile->number) ? $reader->value($profile->number) : null;
            $components = $dictionary instanceof PdfDict ? $reader->resolve($dictionary->get('N')) : null;

            if ($components !== '1' && $components !== '4') {
                throw new CmykConversionFailed(sprintf('RGB is left in the CMYK file: object %d uses an ICC-based colour space with %s components.', $number, is_string($components) ? $components : 'unknown'));
            }
        }

        foreach ($value as $entry) {
            self::scan($reader, $entry, $number);
        }
    }

    private static function content(PdfReader $reader, int $number): void
    {
        $dictionary = $reader->value($number);
        $filter = $dictionary instanceof PdfDict ? $reader->resolve($dictionary->get('Filter')) : null;
        $filter = is_array($filter) && count($filter) === 1 ? $filter[0] : $filter;
        $bytes = $reader->streamBytes($number);

        if ($filter instanceof PdfName && $filter->value === 'FlateDecode') {
            $bytes = @zlib_decode($bytes);
        } elseif ($filter !== null) {
            $bytes = false;
        }

        if ($bytes === false) {
            throw new CmykConversionFailed(sprintf('The content stream %d of the CMYK file cannot be read.', $number));
        }

        PdfSyntax::operations($bytes, static function (string $operator, array $operands) use ($number): null {
            if ($operator === 'rg' || $operator === 'RG') {
                throw new CmykConversionFailed(sprintf('RGB is left in the CMYK file: the content stream %d sets a colour with "%s".', $number, $operator));
            }

            if (($operator === 'cs' || $operator === 'CS') && isset(self::RGB_NAMES[substr($operands[0] ?? '', 1)])) {
                throw new CmykConversionFailed(sprintf('RGB is left in the CMYK file: the content stream %d selects %s.', $number, $operands[0] ?? ''));
            }

            return null;
        });
    }

    private static function picture(PdfReader $reader, int $number, PdfDict $image): void
    {
        $filter = $reader->resolve($image->get('Filter'));
        $filter = is_array($filter) && count($filter) === 1 ? $filter[0] : $filter;

        if (!$filter instanceof PdfName || $filter->value !== 'DCTDecode' || $reader->resolve($image->get('ImageMask')) === 'true') {
            return;
        }

        $space = $reader->resolve($image->get('ColorSpace'));
        $expected = $space instanceof PdfName ? ['DeviceGray' => 1, 'DeviceCMYK' => 4][$space->value] ?? null : null;
        $head = '';

        try {
            $reader->copyStream($number, static function (string $chunk) use (&$head): void {
                $head .= $chunk;

                // the frame header of a JPEG lies behind its metadata segments
                throw new \OverflowException();
            });
        } catch (\OverflowException) {
        }

        $components = self::jpegComponents($head);

        if ($expected === null || $components !== $expected) {
            throw new CmykConversionFailed(sprintf('The picture %d of the CMYK file is a JPEG with %s components in a colour space that needs %s.', $number, $components ?? 'unknown', $expected ?? 'another encoding'));
        }
    }

    /** The number of components a JPEG's frame header declares, or null when none is found in these bytes. */
    public static function jpegComponents(string $head): null|int
    {
        $length = strlen($head);

        if ($length < 4 || $head[0] !== "\xFF" || $head[1] !== "\xD8") {
            return null;
        }

        $at = 2;

        while ($at + 9 < $length) {
            if ($head[$at] !== "\xFF") {
                return null;
            }

            $marker = ord($head[$at + 1]);

            if ($marker === 0xFF) {
                ++$at;

                continue;
            }

            $size = (ord($head[$at + 2]) << 8) | ord($head[$at + 3]);

            // SOF0 … SOF15, except the DHT / JPG / DAC markers that share the range
            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                return ord($head[$at + 9]);
            }

            $at += 2 + $size;
        }

        return null;
    }
}
