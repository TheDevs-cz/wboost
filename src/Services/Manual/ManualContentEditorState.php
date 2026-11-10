<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\ManualContent\ContentPageBackground;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualPage;

/**
 * The document the content editor starts from (and gets back after a save):
 * `{name, layout, segments, background}` exactly as the save endpoint accepts
 * it, plus a display-only `url` on every image element and the background
 * (ignored on the way back in) and `unavailable` (null | `trashed` | `missing` — the picture went to the
 * gallery Koš / its row is gone; the editor marks it, content pages P3).
 */
readonly final class ManualContentEditorState
{
    public function __construct(
        private UploaderHelper $uploaderHelper,
        private ManualImageAvailability $availability,
    ) {
    }

    /**
     * @return array{name: string, layout: string, segments: list<array<string, mixed>>, background: null|array<string, mixed>}
     */
    public function forIntro(Manual $manual, null|ManualContentPage $introPage): array
    {
        if ($introPage !== null) {
            return $this->forPage($introPage);
        }

        return $this->state(
            $manual,
            IntroContentPageDefaults::name($manual->pageTextTitleOverride(ManualPage::Intro)),
            IntroContentPageDefaults::LAYOUT->value,
            IntroContentPageDefaults::segments($manual->pageDescriptionOverride(ManualPage::Intro), null),
        );
    }

    /**
     * @return array{name: string, layout: string, segments: list<array<string, mixed>>, background: null|array<string, mixed>}
     */
    public function forPage(ManualContentPage $page): array
    {
        return $this->state($page->manual, $page->name, $page->layout->value, $page->segments, $page->background);
    }

    /**
     * @param list<ContentSegment> $segments
     * @return array{name: string, layout: string, segments: list<array<string, mixed>>, background: null|array<string, mixed>}
     */
    private function state(Manual $manual, string $name, string $layout, array $segments, null|ContentPageBackground $background = null): array
    {
        $backgroundData = null;

        if ($background !== null) {
            $backgroundData = $background->toArray() + [
                'url' => $this->uploaderHelper->getPublicPath($background->path),
                'unavailable' => $this->availability->state($manual, $background->path, $background->galleryImageId),
            ];
        }

        $data = [];

        foreach ($segments as $segment) {
            $elements = [];

            foreach ($segment->elements as $element) {
                $array = $element->toArray();

                if ($element instanceof ImageElement) {
                    $array['url'] = $this->uploaderHelper->getPublicPath($element->path);
                    $array['unavailable'] = $this->availability->state($manual, $element->path, $element->galleryImageId);
                }

                $elements[] = $array;
            }

            $data[] = ['verticalAlign' => $segment->verticalAlign->value, 'elements' => $elements];
        }

        return ['name' => $name, 'layout' => $layout, 'segments' => $data, 'background' => $backgroundData];
    }
}
