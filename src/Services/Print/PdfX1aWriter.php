<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use WBoost\Web\Value\PrintPage;
use WBoost\Web\Value\PrintPageGeometry;

/**
 * Writes a PDF/X-1a:2001 (ISO 15930-1) document: per page one CMYK image
 * filling its bleed box + optional crop marks, and ONE FOGRA39 output intent
 * for the whole file — structurally what Illustrator / InDesign export for a
 * flattened print file, which is what the studio's printers accept.
 *
 * Hand-written on purpose: the document is a handful of objects, and owning
 * every byte keeps it deterministic and inside the standard (PDF 1.3, no
 * transparency, CMYK + registration colour only, all boxes, trailer ID,
 * Trapped key) without a PDF library's defaults to fight.
 *
 * Object layout ({@see PdfObjects} allocates the numbers): catalog, pages
 * tree, ICC profile stream, registration colour space and Info once; then
 * page + image XObject + content stream per page. Each page carries its own
 * MediaBox / TrimBox / BleedBox (pages of one document normally share a
 * size, but nothing here assumes it).
 */
final class PdfX1aWriter
{
    public const string OUTPUT_CONDITION = 'Coated FOGRA39 (ISO 12647-2:2004)';
    public const string OUTPUT_CONDITION_IDENTIFIER = 'FOGRA39';
    public const string REGISTRY = 'http://www.color.org';

    /**
     * @param list<PrintPage> $pages in reading order, at least one
     */
    public function write(
        array $pages,
        string $outputProfile,
        string $title,
        \DateTimeImmutable $createdAt,
    ): string {
        if ($pages === []) {
            throw new \InvalidArgumentException('A print PDF needs at least one page.');
        }

        $date = PdfObjects::date($createdAt);
        $objects = new PdfObjects();

        $catalog = $objects->reserve();
        $pagesTree = $objects->reserve();
        $icc = $objects->add(PdfObjects::stream('/N 4 /Filter /FlateDecode', PdfObjects::deflate($outputProfile)));
        // Registration colour ("All"): ink on every plate, for the marks.
        $registration = $objects->add('[ /Separation /All /DeviceCMYK << /FunctionType 2 /Domain [ 0 1 ] /C0 [ 0 0 0 0 ] /C1 [ 1 1 1 1 ] /N 1 >> ]');
        $info = $objects->add('<< /Title ' . PdfObjects::text($title)
            . ' /Creator (WBoost) /Producer (WBoost print export)'
            . ' /CreationDate (' . $date . ') /ModDate (' . $date . ')'
            . ' /Trapped /False'
            . ' /GTS_PDFXVersion (PDF/X-1:2001) /GTS_PDFXConformance (PDF/X-1a:2001) >>');

        $kids = [];
        foreach ($pages as $page) {
            $raster = $page->raster;
            $geometry = $page->geometry;

            $pageNumber = $objects->reserve();
            $image = $objects->add(PdfObjects::stream(
                '/Type /XObject /Subtype /Image /Width ' . $raster->width . ' /Height ' . $raster->height
                . ' /ColorSpace /DeviceCMYK /BitsPerComponent 8 /Filter /DCTDecode'
                . (self::isAdobeInverted($raster->jpeg) ? ' /Decode [ 1 0 1 0 1 0 1 0 ]' : ''),
                $raster->jpeg,
            ));
            $content = $objects->add(PdfObjects::stream('/Filter /FlateDecode', PdfObjects::deflate($this->content($geometry))));

            $objects->set($pageNumber, '<< /Type /Page /Parent ' . PdfObjects::ref($pagesTree)
                . ' /MediaBox ' . PdfObjects::box($geometry->mediaBox())
                . ' /TrimBox ' . PdfObjects::box($geometry->trimBox())
                . ' /BleedBox ' . PdfObjects::box($geometry->bleedBox())
                . ' /Resources << /ProcSet [ /PDF /ImageC ] /XObject << /Im0 ' . PdfObjects::ref($image) . ' >>'
                . ' /ColorSpace << /CS0 ' . PdfObjects::ref($registration) . ' >> >>'
                . ' /Contents ' . PdfObjects::ref($content) . ' >>');

            $kids[] = PdfObjects::ref($pageNumber);
        }

        $objects->set($catalog, '<< /Type /Catalog /Pages ' . PdfObjects::ref($pagesTree)
            . ' /OutputIntents [ << /Type /OutputIntent /S /GTS_PDFX'
            . ' /OutputCondition ' . PdfObjects::text(self::OUTPUT_CONDITION)
            . ' /OutputConditionIdentifier ' . PdfObjects::text(self::OUTPUT_CONDITION_IDENTIFIER)
            . ' /RegistryName ' . PdfObjects::text(self::REGISTRY)
            . ' /Info ' . PdfObjects::text(self::OUTPUT_CONDITION)
            . ' /DestOutputProfile ' . PdfObjects::ref($icc) . ' >> ] >>');
        $objects->set($pagesTree, '<< /Type /Pages /Kids [ ' . implode(' ', $kids) . ' ] /Count ' . count($kids) . ' >>');

        $id = PdfObjects::documentId($title, $date, array_map(static fn (PrintPage $page): string => $page->raster->jpeg, $pages));

        return $objects->toPdf('1.3', $catalog, $info, $id);
    }

    private function content(PrintPageGeometry $geometry): string
    {
        [$bx0, $by0, $bx1, $by1] = $geometry->bleedBox();

        $content = sprintf(
            "q %s 0 0 %s %s %s cm /Im0 Do Q\n",
            PdfObjects::number($bx1 - $bx0),
            PdfObjects::number($by1 - $by0),
            PdfObjects::number($bx0),
            PdfObjects::number($by0),
        );

        if (!$geometry->cropMarks) {
            return $content;
        }

        $paths = $this->cropMarkPaths($geometry);

        // White halo first (tint 0 of "All" = knock out every plate), then
        // the registration hairline on top — Illustrator's crop marks.
        return $content
            . "q /CS0 CS\n"
            . PdfObjects::number(PrintPageGeometry::MARK_HALO_PT) . " w 0 SCN\n" . $paths . "S\n"
            . PdfObjects::number(PrintPageGeometry::MARK_WEIGHT_PT) . " w 1 SCN\n" . $paths . "S\n"
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
                $paths .= sprintf("%s %s m %s %s l\n", PdfObjects::number($x + $dx * $offset), PdfObjects::number($y), PdfObjects::number($x + $dx * $end), PdfObjects::number($y));
                // …and the vertical one.
                $paths .= sprintf("%s %s m %s %s l\n", PdfObjects::number($x), PdfObjects::number($y + $dy * $offset), PdfObjects::number($x), PdfObjects::number($y + $dy * $end));
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
}
