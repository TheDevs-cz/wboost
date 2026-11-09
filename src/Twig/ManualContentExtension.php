<?php

declare(strict_types=1);

namespace WBoost\Web\Twig;

use Twig\Attribute\AsTwigFunction;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Services\Manual\ManualImageAvailability;
use WBoost\Web\Services\Manual\RichTextHtmlRenderer;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentStyles;
use WBoost\Web\Value\ManualContent\RichTextDoc;

/**
 * Manual content pages (`_manual_content_page.html.twig`).
 */
readonly final class ManualContentExtension
{
    public function __construct(
        private RichTextHtmlRenderer $renderer,
        private ManualImageAvailability $availability,
    ) {
    }

    /**
     * Null while the picture is a live gallery picture (or one the gallery
     * never knew); `trashed` / `missing` when it went to the Koš / its gallery
     * row is gone — the manual then shows a stand-in to editors and nothing
     * to readers (content pages P3).
     */
    #[AsTwigFunction('manual_image_unavailable')]
    public function imageUnavailable(Manual $manual, string $path, null|string $galleryImageId = null): null|string
    {
        return $this->availability->state($manual, $path, $galleryImageId);
    }

    /**
     * Whether the manual shows this content page at all (and numbers it).
     * Editors see every page with content — an unavailable picture as a
     * stand-in; readers only pages with something they can actually see, so
     * a page whose only content is a trashed / missing picture is skipped
     * for them instead of rendering an empty numbered page (content pages P4).
     */
    #[AsTwigFunction('manual_content_page_shown')]
    public function contentPageShown(ManualContentPage $page, bool $editor): bool
    {
        if ($editor) {
            return $page->hasContent();
        }

        return $page->hasContentShowing(
            fn (ImageElement $image): bool => $this->availability->state($page->manual, $image->path, $image->galleryImageId) === null,
        );
    }

    /**
     * A rich-text element as HTML. Safe by construction: the renderer emits
     * only allow-listed markup with every value escaped.
     */
    #[AsTwigFunction('manual_rich_text', isSafe: ['html'])]
    public function richText(RichTextDoc $doc, ManualContentStyles $styles): string
    {
        return $this->renderer->render($doc, $styles);
    }
}
