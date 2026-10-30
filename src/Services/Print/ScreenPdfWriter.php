<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print;

use WBoost\Web\Value\ScreenPdfPage;

/**
 * Writes the screen PDF ("PDF k odeslání"): PDF 1.4, per page one sRGB
 * (DeviceRGB) JPEG covering the whole page, the page being the trim size —
 * no bleed, no crop marks, no output intent. Meant for e-mail / on-screen
 * reading, not for a press ({@see PdfX1aWriter} is the print file).
 *
 * Object layout: catalog, pages tree, Info; then page + image XObject +
 * content stream per page.
 */
final class ScreenPdfWriter
{
    /**
     * @param list<ScreenPdfPage> $pages in reading order, at least one
     */
    public function write(array $pages, string $title, \DateTimeImmutable $createdAt): string
    {
        if ($pages === []) {
            throw new \InvalidArgumentException('A screen PDF needs at least one page.');
        }

        $date = PdfObjects::date($createdAt);
        $objects = new PdfObjects();

        $catalog = $objects->reserve();
        $pagesTree = $objects->reserve();
        $info = $objects->add('<< /Title ' . PdfObjects::text($title)
            . ' /Creator (WBoost) /Producer (WBoost screen export)'
            . ' /CreationDate (' . $date . ') /ModDate (' . $date . ') >>');

        $kids = [];
        foreach ($pages as $page) {
            $pageNumber = $objects->reserve();
            $image = $objects->add(PdfObjects::stream(
                '/Type /XObject /Subtype /Image /Width ' . $page->width . ' /Height ' . $page->height
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode',
                $page->jpeg,
            ));
            $content = $objects->add(PdfObjects::stream('/Filter /FlateDecode', PdfObjects::deflate(sprintf(
                "q %s 0 0 %s 0 0 cm /Im0 Do Q\n",
                PdfObjects::number($page->widthPt),
                PdfObjects::number($page->heightPt),
            ))));

            $objects->set($pageNumber, '<< /Type /Page /Parent ' . PdfObjects::ref($pagesTree)
                . ' /MediaBox ' . PdfObjects::box([0.0, 0.0, $page->widthPt, $page->heightPt])
                . ' /Resources << /ProcSet [ /PDF /ImageC ] /XObject << /Im0 ' . PdfObjects::ref($image) . ' >> >>'
                . ' /Contents ' . PdfObjects::ref($content) . ' >>');

            $kids[] = PdfObjects::ref($pageNumber);
        }

        $objects->set($catalog, '<< /Type /Catalog /Pages ' . PdfObjects::ref($pagesTree) . ' >>');
        $objects->set($pagesTree, '<< /Type /Pages /Kids [ ' . implode(' ', $kids) . ' ] /Count ' . count($kids) . ' >>');

        $id = PdfObjects::documentId($title, $date, array_map(static fn (ScreenPdfPage $page): string => $page->jpeg, $pages));

        return $objects->toPdf('1.4', $catalog, $info, $id);
    }
}
