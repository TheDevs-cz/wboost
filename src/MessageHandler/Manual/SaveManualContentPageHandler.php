<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Manual;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Exceptions\ManualNotFound;
use WBoost\Web\Message\Manual\SaveManualContentPage;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Repository\ManualRepository;
use WBoost\Web\Services\Manual\ManualPagePositions;
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
        private ManualPagePositions $positions,
        private FileUploadRepository $fileUploadRepository,
    ) {
    }

    /**
     * @throws ManualNotFound
     * @throws InvalidManualContent
     */
    public function __invoke(SaveManualContentPage $message): void
    {
        $manual = $this->manualRepository->get($message->manualId);
        $segments = $this->sanitize($message->segments, $manual, $this->resolveStyles->forManual($manual));
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
            // The intro is pinned first; a custom page joins the end of the
            // list it shares with the mockup pages.
            $message->role === ManualContentPageRole::Intro ? 0 : $this->positions->next($manual->id),
            $now,
        ));
    }

    /**
     * Images may only reference this project's pictures (an id or key typed
     * into the payload must not pull another client's file into a public
     * page); fonts / colours the manual does not allow are dropped from the
     * text.
     *
     * A gallery pick (`galleryImageId`) is resolved server-side: the row must
     * belong to the manual's project and its key REPLACES whatever path the
     * client sent (the path is only a snapshot of the row). A picked id whose
     * row is gone (purged) keeps its element — the render shows the stand-in
     * — as long as the path is still one of this manual/project's own keys.
     *
     * @param list<ContentSegment> $segments
     * @return list<ContentSegment>
     * @throws InvalidManualContent
     */
    private function sanitize(array $segments, Manual $manual, ManualContentStyles $styles): array
    {
        return array_map(
            fn (ContentSegment $segment): ContentSegment => $segment->withElements(array_map(
                function (ContentElement $element) use ($manual, $styles): ContentElement {
                    if ($element instanceof ImageElement) {
                        return $this->sanitizeImage($element, $manual);
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

    /**
     * @throws InvalidManualContent
     */
    private function sanitizeImage(ImageElement $element, Manual $manual): ImageElement
    {
        if ($element->galleryImageId !== null) {
            $file = $this->fileUploadRepository->find(Uuid::fromString($element->galleryImageId));

            if ($file !== null) {
                if (!$file->project->id->equals($manual->project->id)) {
                    throw new InvalidManualContent('The picture belongs to another project.');
                }

                // The snapshot must stay decodable (ImageElement is strict on read).
                if (!ImageElement::isValidPath($file->path)) {
                    throw new InvalidManualContent('This gallery picture can not be used in a manual.');
                }

                return $element->withGalleryImage($file->id->toString(), $file->path);
            }
        }

        if (!$element->belongsToManual($manual->id->toString()) && !$element->isGalleryUploadOf($manual->project->id->toString())) {
            throw new InvalidManualContent('The picture belongs to another manual.');
        }

        return $element;
    }
}
