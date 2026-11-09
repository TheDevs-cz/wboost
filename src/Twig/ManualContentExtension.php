<?php

declare(strict_types=1);

namespace WBoost\Web\Twig;

use Twig\Attribute\AsTwigFunction;
use WBoost\Web\Services\Manual\RichTextHtmlRenderer;
use WBoost\Web\Value\ManualContent\ManualContentStyles;
use WBoost\Web\Value\ManualContent\RichTextDoc;

/**
 * Manual content pages (`_manual_content_page.html.twig`).
 */
readonly final class ManualContentExtension
{
    public function __construct(
        private RichTextHtmlRenderer $renderer,
    ) {
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
