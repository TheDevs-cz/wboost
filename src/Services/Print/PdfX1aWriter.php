<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use WBoost\Web\Value\PrintRaster;
use WBoost\Web\Value\PrintPageGeometry;

/**
 * Writes a single-page PDF/X-1a:2001 (ISO 15930-1) document: one CMYK image
 * filling the bleed box, optional crop marks, and the FOGRA39 output intent —
 * structurally what Illustrator exports for a flattened print file, which is
 * what the studio's printers accept.
 *
 * Hand-written on purpose: the document is a handful of objects, and owning
 * every byte keeps it deterministic and inside the standard (PDF 1.3, no
 * transparency, CMYK + registration colour only, all boxes, trailer ID,
 * Trapped key) without a PDF library's defaults to fight.
 */
final class PdfX1aWriter
{
    public const string OUTPUT_CONDITION = 'Coated FOGRA39 (ISO 12647-2:2004)';
    public const string OUTPUT_CONDITION_IDENTIFIER = 'FOGRA39';
    public const string REGISTRY = 'http://www.color.org';

    public function write(
        PrintRaster $raster,
        PrintPageGeometry $geometry,
        string $outputProfile,
        string $title,
        \DateTimeImmutable $createdAt,
    ): string {
        $date = self::date($createdAt);
        $iccStream = self::deflate($outputProfile);
        $content = self::deflate($this->content($geometry));

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R /OutputIntents [ << /Type /OutputIntent /S /GTS_PDFX'
                . ' /OutputCondition ' . self::text(self::OUTPUT_CONDITION)
                . ' /OutputConditionIdentifier ' . self::text(self::OUTPUT_CONDITION_IDENTIFIER)
                . ' /RegistryName ' . self::text(self::REGISTRY)
                . ' /Info ' . self::text(self::OUTPUT_CONDITION)
                . ' /DestOutputProfile 4 0 R >> ] >>',
            2 => '<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R'
                . ' /MediaBox ' . self::box($geometry->mediaBox())
                . ' /TrimBox ' . self::box($geometry->trimBox())
                . ' /BleedBox ' . self::box($geometry->bleedBox())
                . ' /Resources << /ProcSet [ /PDF /ImageC ] /XObject << /Im0 5 0 R >> /ColorSpace << /CS0 7 0 R >> >>'
                . ' /Contents 6 0 R >>',
            4 => ['<< /N 4 /Filter /FlateDecode /Length ' . strlen($iccStream) . ' >>', $iccStream],
            5 => ['<< /Type /XObject /Subtype /Image /Width ' . $raster->width . ' /Height ' . $raster->height
                . ' /ColorSpace /DeviceCMYK /BitsPerComponent 8 /Filter /DCTDecode'
                . (self::isAdobeInverted($raster->jpeg) ? ' /Decode [ 1 0 1 0 1 0 1 0 ]' : '')
                . ' /Length ' . strlen($raster->jpeg) . ' >>', $raster->jpeg],
            6 => ['<< /Filter /FlateDecode /Length ' . strlen($content) . ' >>', $content],
            // Registration colour ("All"): ink on every plate, for the marks.
            7 => '[ /Separation /All /DeviceCMYK << /FunctionType 2 /Domain [ 0 1 ] /C0 [ 0 0 0 0 ] /C1 [ 1 1 1 1 ] /N 1 >> ]',
            8 => '<< /Title ' . self::text($title)
                . ' /Creator (WBoost) /Producer (WBoost print export)'
                . ' /CreationDate (' . $date . ') /ModDate (' . $date . ')'
                . ' /Trapped /False'
                . ' /GTS_PDFXVersion (PDF/X-1:2001) /GTS_PDFXConformance (PDF/X-1a:2001) >>',
        ];

        // Header + a binary comment line so transfer tools treat the file as binary.
        $pdf = "%PDF-1.3\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n";

            if (is_array($object)) {
                $pdf .= $object[0] . "\nstream\n" . $object[1] . "\nendstream";
            } else {
                $pdf .= $object;
            }

            $pdf .= "\nendobj\n";
        }

        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $id = md5($title . $date . strlen($raster->jpeg) . md5($raster->jpeg));
        $pdf .= "trailer\n<< /Size " . $size . ' /Root 1 0 R /Info 8 0 R /ID [ <' . $id . '> <' . $id . "> ] >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF\n";

        return $pdf;
    }

    private function content(PrintPageGeometry $geometry): string
    {
        [$bx0, $by0, $bx1, $by1] = $geometry->bleedBox();

        $content = sprintf(
            "q %s 0 0 %s %s %s cm /Im0 Do Q\n",
            self::number($bx1 - $bx0),
            self::number($by1 - $by0),
            self::number($bx0),
            self::number($by0),
        );

        if (!$geometry->cropMarks) {
            return $content;
        }

        $paths = $this->cropMarkPaths($geometry);

        // White halo first (tint 0 of "All" = knock out every plate), then
        // the registration hairline on top — Illustrator's crop marks.
        return $content
            . "q /CS0 CS\n"
            . self::number(PrintPageGeometry::MARK_HALO_PT) . " w 0 SCN\n" . $paths . "S\n"
            . self::number(PrintPageGeometry::MARK_WEIGHT_PT) . " w 1 SCN\n" . $paths . "S\n"
            . "Q\n";
    }

    private function cropMarkPaths(PrintPageGeometry $geometry): string
    {
        [$x0, $y0, $x1, $y1] = $geometry->trimBox();
        $offset = $geometry->markOffset();
        $end = $offset + PrintPageGeometry::MARK_LENGTH_PT;
        $paths = '';

        foreach ([[$x0, -1], [$x1, 1]] as [$x, $dx]) {
            foreach ([[$y0, -1], [$y1, 1]] as [$y, $dy]) {
                // Horizontal mark on the trim line, pointing away from the page…
                $paths .= sprintf("%s %s m %s %s l\n", self::number($x + $dx * $offset), self::number($y), self::number($x + $dx * $end), self::number($y));
                // …and the vertical one.
                $paths .= sprintf("%s %s m %s %s l\n", self::number($x), self::number($y + $dy * $offset), self::number($x), self::number($y + $dy * $end));
            }
        }

        return $paths;
    }

    /**
     * Photoshop / ImageMagick write CMYK JPEGs with an Adobe APP14 marker and
     * INVERTED samples; a PDF reader takes DCT CMYK data literally, so those
     * need the inverting Decode array.
     */
    public static function isAdobeInverted(string $jpeg): bool
    {
        $offset = 2;
        $length = strlen($jpeg);

        while ($offset + 4 <= $length && $jpeg[$offset] === "\xFF") {
            $marker = ord($jpeg[$offset + 1]);
            $segment = (ord($jpeg[$offset + 2]) << 8) | ord($jpeg[$offset + 3]);

            if ($marker === 0xEE && substr($jpeg, $offset + 4, 5) === 'Adobe') {
                return true;
            }

            if ($marker === 0xDA) {
                break;
            }

            $offset += 2 + $segment;
        }

        return false;
    }

    private static function deflate(string $bytes): string
    {
        $compressed = gzcompress($bytes, 9);

        if ($compressed === false) {
            throw new \RuntimeException('Could not deflate a PDF stream.');
        }

        return $compressed;
    }

    /**
     * @param array{float, float, float, float} $box
     */
    private static function box(array $box): string
    {
        return '[ ' . implode(' ', array_map(self::number(...), $box)) . ' ]';
    }

    private static function number(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }

    /**
     * A PDF text string: plain literal for ASCII, UTF-16BE hex otherwise.
     */
    private static function text(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return '(' . strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';
        }

        $utf16 = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');

        return '<FEFF' . strtoupper(bin2hex($utf16)) . '>';
    }

    private static function date(\DateTimeImmutable $date): string
    {
        $offset = $date->format('P');

        return 'D:' . $date->format('YmdHis') . ($offset === '+00:00' ? 'Z' : str_replace(':', "'", $offset) . "'");
    }
}
