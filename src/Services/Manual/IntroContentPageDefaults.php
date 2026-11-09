<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use WBoost\Web\Value\ManualContent\ContentElements;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\RichTextDoc;
use WBoost\Web\Value\ManualContent\RichTextElement;
use WBoost\Web\Value\ManualPage;
use WBoost\Web\Value\MockupPageLayout;

/**
 * What an intro content page starts as — the same thing the legacy intro
 * rendered: the page heading (`ManualPage::Intro` title, admin override
 * first) becomes the page NAME, which the heading row prints exactly where
 * the old title stood; the description override (the default is empty)
 * becomes a rich-text element; a picture (only the P1 data migration still
 * passes one — the legacy `manual.intro_image` column was dropped in P4) an
 * image element at its natural size. One full-width segment (layout-7).
 *
 * Pure on purpose: the P1 data migration and the editor (a manual that never
 * had an intro page) build the same thing.
 */
final class IntroContentPageDefaults
{
    public const MockupPageLayout LAYOUT = MockupPageLayout::Layout7;

    public static function name(null|string $titleOverride): string
    {
        $titleOverride = $titleOverride !== null ? trim($titleOverride) : null;

        return $titleOverride !== null && $titleOverride !== '' ? $titleOverride : ManualPage::Intro->defaultTitle();
    }

    /**
     * @return list<ContentSegment> exactly one (LAYOUT has one slot)
     */
    public static function segments(null|string $descriptionOverride, null|string $introImagePath): array
    {
        $elements = [];

        if ($descriptionOverride !== null && trim($descriptionOverride) !== '') {
            $elements[] = new RichTextElement(ContentElements::newId(), RichTextDoc::fromPlainText($descriptionOverride));
        }

        if ($introImagePath !== null && ImageElement::isValidPath($introImagePath)) {
            $elements[] = self::image($introImagePath);
        }

        return [new ContentSegment(elements: $elements)];
    }

    public static function image(string $path): ImageElement
    {
        return new ImageElement(ContentElements::newId(), $path, ContentImageFit::Natural, ManualPage::Intro->defaultTitle());
    }
}
