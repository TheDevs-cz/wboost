<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\SaveManualContentPage;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Manual\ResolveManualContentStyles;
use WBoost\Web\Value\ManualContent\ContentElement;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\ManualContent\ManualContentStyles;
use WBoost\Web\Value\ManualContent\RichTextElement;

#[AsMessageHandler]
readonly final class SaveManualContentPageHandler
{
    public function __construct(
        private ManualRepository $manualRepository,
        private ManualContentPageRepository $contentPageRepository,
        private ResolveManualContentStyles $resolveStyles,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ManualNotFound
     * @throws InvalidManualContent
     */
    public function __invoke(SaveManualContentPage $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);
        $segments = $this->sanitize($message->segments, $message->manualId->toString(), $this->resolveStyles->forManual($manual));
        $now = $this->clock->now();

        $page = $this->contentPageRepository->find($message->pageId);

        if ($page === null && $message->role === ManualContentPageRole::Intro) {
            $page = $this->contentPageRepository->introFor($manual->id);
        }

        if ($page !== null) {
            if (!$page->manual->id->equals($manual->id) || $page->role !== $message->role) {
                throw new InvalidManualContent('The page belongs to another manual.');
            }

            $page->edit($message->name, $message->layout, $segments, $now);

            return;
        }

        $this->contentPageRepository->add(new ManualContentPage(
            $message->pageId,
            $manual,
            $message->role,
            $message->name,
            $message->layout,
            $segments,
            0,
            $now,
        ));
    }

    /**
     * Images may only reference this manual's own storage (an id typed into
     * the payload must not pull another client's file into a public page);
     * fonts / colours the manual does not allow are dropped from the text.
     *
     * @param list<ContentSegment> $segments
     * @return list<ContentSegment>
     * @throws InvalidManualContent
     */
    private function sanitize(array $segments, string $manualId, ManualContentStyles $styles): array
    {
        return array_map(
            static fn (ContentSegment $segment): ContentSegment => $segment->withElements(array_map(
                static function (ContentElement $element) use ($manualId, $styles): ContentElement {
                    if ($element instanceof ImageElement && !$element->belongsToManual($manualId)) {
                        throw new InvalidManualContent('The picture belongs to another manual.');
                    }

                    if ($element instanceof RichTextElement) {
                        return $element->withDoc($element->doc->restrictStyles($styles));
                    }

                    return $element;
                },
                $segment->elements,
            )),
            $segments,
        );
    }
}
