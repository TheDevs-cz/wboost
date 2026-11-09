<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Value\ManualContent\ContentElement;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\ManualPage;

/**
 * Keeps the legacy "Úvodní obrázek" upload of the add / edit manual form
 * working until P4 removes it: the uploaded picture goes INTO the intro
 * content page, which is what the manual renders.
 *
 * - no intro page yet → one is created from {@see IntroContentPageDefaults}
 *   (the manual's intro page texts + the picture);
 * - an intro page exists → the image element still showing the PREVIOUS
 *   upload gets the new key (fit, alt and position kept); when the admin
 *   has removed that picture in the editor, the new one is appended to the
 *   first segment.
 */
readonly final class IntroContentPageImage
{
    public function __construct(
        private ManualContentPageRepository $contentPageRepository,
        private ClockInterface $clock,
    ) {
    }

    public function apply(Manual $manual, null|string $previousPath, string $newPath): void
    {
        $now = $this->clock->now();
        $page = $this->contentPageRepository->introFor($manual->id);

        if ($page === null) {
            $this->contentPageRepository->add(new ManualContentPage(
                Uuid::uuid7(),
                $manual,
                ManualContentPageRole::Intro,
                IntroContentPageDefaults::name($manual->pageTextTitleOverride(ManualPage::Intro)),
                IntroContentPageDefaults::LAYOUT,
                IntroContentPageDefaults::segments($manual->pageDescriptionOverride(ManualPage::Intro), $newPath),
                0,
                $now,
            ));

            return;
        }

        $replaced = false;
        $segments = [];

        foreach ($page->segments as $segment) {
            $segments[] = $segment->withElements(array_map(
                static function (ContentElement $element) use ($previousPath, $newPath, &$replaced): ContentElement {
                    if (!$replaced && $previousPath !== null && $element instanceof ImageElement && $element->path === $previousPath) {
                        $replaced = true;

                        return $element->withPath($newPath);
                    }

                    return $element;
                },
                $segment->elements,
            ));
        }

        if (!$replaced) {
            $segments[0] = $segments[0]->withElements([...$segments[0]->elements, IntroContentPageDefaults::image($newPath)]);
        }

        $page->edit($page->name, $page->layout, $segments, $now);
    }
}
