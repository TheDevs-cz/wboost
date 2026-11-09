<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualPage;

/**
 * The document the content editor starts from (and gets back after a save):
 * `{name, layout, segments}` exactly as the save endpoint accepts it, plus a
 * display-only `url` on every image element (ignored on the way back in).
 */
readonly final class ManualContentEditorState
{
    public function __construct(
        private UploaderHelper $uploaderHelper,
    ) {
    }

    /**
     * @return array{name: string, layout: string, segments: list<array<string, mixed>>}
     */
    public function forIntro(Manual $manual, null|ManualContentPage $introPage): array
    {
        if ($introPage !== null) {
            return $this->state($introPage->name, $introPage->layout->value, $introPage->segments);
        }

        return $this->state(
            IntroContentPageDefaults::name($manual->pageTextTitleOverride(ManualPage::Intro)),
            IntroContentPageDefaults::LAYOUT->value,
            IntroContentPageDefaults::segments($manual->pageDescriptionOverride(ManualPage::Intro), $manual->introImage),
        );
    }

    /**
     * @param list<ContentSegment> $segments
     * @return array{name: string, layout: string, segments: list<array<string, mixed>>}
     */
    private function state(string $name, string $layout, array $segments): array
    {
        $data = [];

        foreach ($segments as $segment) {
            $elements = [];

            foreach ($segment->elements as $element) {
                $array = $element->toArray();

                if ($element instanceof ImageElement) {
                    $array['url'] = $this->uploaderHelper->getPublicPath($element->path);
                }

                $elements[] = $array;
            }

            $data[] = ['verticalAlign' => $segment->verticalAlign->value, 'elements' => $elements];
        }

        return ['name' => $name, 'layout' => $layout, 'segments' => $data];
    }
}
