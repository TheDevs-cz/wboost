<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Exceptions\AiScanNotFound;
use WBoost\Web\Exceptions\TemplateRenderUnavailable;
use WBoost\Web\Mcp\Design\CandidateRenderer;
use WBoost\Web\Mcp\Design\DesignPreflight;
use WBoost\Web\Message\AiScan\AcceptTemplateScan;
use WBoost\Web\Message\Template\AddTemplate;
use WBoost\Web\Message\Template\AddTemplateVariant;
use WBoost\Web\Message\Template\EditTemplateVariantCanvasEditor;
use WBoost\Web\Message\Template\StoreTemplateVariantPreviewImage;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Services\AiScan\AiScanTransitions;
use WBoost\Web\Services\AiScan\Template\ApplyFixes;
use WBoost\Web\Services\AiScan\Template\ScanGalleryFolders;
use WBoost\Web\Services\AiScan\Template\ScanProposal;
use WBoost\Web\Services\AiScan\Template\ScanRenderLimiter;
use WBoost\Web\Services\AiScan\Template\TransientVariant;
use WBoost\Web\Services\AiScan\Template\UnfilledPictures;
use WBoost\Web\Services\Image\DownscaleImage;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;
use WBoost\Web\Value\AiScan\AiScanStatus;
use WBoost\Web\Value\RenderImageFormat;

/**
 * Rule 14 for templates. Under the scan's row lock, in the bus's transaction:
 *
 * 1. a repeat on an `accepted` scan answers with the first result;
 * 2. the references are checked again — a font removed or a picture trashed
 *    since the review is `stale_references`, **nothing is created** and the
 *    review lists them as items;
 * 3. the template document is compiled and rendered on a transient variant
 *    BEFORE anything is created — a renderer that is away (`renderer_busy`)
 *    leaves no template behind;
 * 4. the template and its variant are created through the existing commands
 *    (`AddTemplate`, `AddTemplateVariant`, `EditTemplateVariantCanvasEditor`,
 *    `StoreTemplateVariantPreviewImage`) and the scan becomes `accepted`.
 *
 * A place left empty references the project's stand-in row from here on
 * ({@see AiScanStandIn::projectFile()}); {@see UnfilledPictures} finds it.
 */
#[AsMessageHandler]
readonly final class AcceptTemplateScanHandler
{
    /** Where the stand-in row's id goes, until it is known whether one is needed. */
    private const string STAND_IN = '00000000-0000-4000-8000-00005ca0f111';
    private const int THUMBNAIL_LONG_EDGE = 1000;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private AiScanRepository $repository,
        private TemplateVariantRepository $variants,
        private AiScanTransitions $transitions,
        private ScanProposal $proposal,
        private AiScanStandIn $standIn,
        private TransientVariant $transientVariant,
        private DesignPreflight $preflight,
        private CandidateRenderer $renderer,
        private ScanRenderLimiter $renderLimiter,
        private DownscaleImage $downscaleImage,
        private ScanGalleryFolders $folders,
        private UnfilledPictures $unfilledPictures,
        private ProvideIdentity $provideIdentity,
    ) {
    }

    /**
     * @return array{templateId: null|string, variantId: null|string, elements: int, unfilledPictures: int, created: bool}
     *
     * @throws AiScanNotFound
     * @throws AiScanRefused `wrong_state`, `stale_references`, `renderer_busy`, `invalid_fix`
     */
    public function __invoke(AcceptTemplateScan $message): array
    {
        $status = $this->connection->fetchOne('SELECT status FROM ai_scan WHERE id = :id FOR UPDATE', ['id' => $message->scanId->toString()]);

        if ($status === false) {
            throw new AiScanNotFound();
        }

        $scan = $this->repository->get($message->scanId);

        if ($scan->status === AiScanStatus::Accepted && $scan->kind === AiScanKind::Template) {
            return $this->result($scan, 0, false);
        }

        if ($scan->status !== AiScanStatus::Ready || $scan->kind !== AiScanKind::Template) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        $stale = $this->proposal->staleReferences($scan);

        if ($stale['fonts'] !== [] || $stale['pictures'] !== []) {
            throw new AiScanRefused(AiScanRefusal::StaleReferences, ['fonts' => count($stale['fonts']), 'pictures' => count($stale['pictures'])]);
        }

        $state = ScanProposal::state($scan);
        $name = $this->proposal->name($scan, $state);
        $document = $this->proposal->document($scan, $state, self::STAND_IN);
        $dimension = ScanProposal::dimension($scan, $document);
        $document = ApplyFixes::scaled($document, $dimension->width(), $dimension->height());
        $document = $this->withStandInRow($scan, $document);

        // Compiled and drawn before anything exists.
        $transient = $this->transientVariant->for($scan->project, $dimension->width(), $dimension->height());
        $review = $this->preflight->review($transient, $document);

        if ($review->compiled === null) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['reason' => 'design']);
        }

        $slot = $this->renderLimiter->acquire();

        if ($slot === null) {
            throw new AiScanRefused(AiScanRefusal::RendererBusy);
        }

        try {
            $rendered = $this->renderer->renderToBytes($transient, $review->compiled, format: RenderImageFormat::Png);
        } catch (TemplateRenderUnavailable) {
            throw new AiScanRefused(AiScanRefusal::RendererBusy);
        } finally {
            $this->renderLimiter->release($slot);
        }

        $templateId = $this->provideIdentity->next();
        $variantId = $this->provideIdentity->next();

        $this->bus->dispatch(new AddTemplate($scan->project->id, $templateId, null, $name, null));
        $this->bus->dispatch(new AddTemplateVariant($templateId, $variantId, $dimension, null));

        $variant = $this->variants->get($variantId);
        $compiled = $this->preflight->review($variant, $document)->compiled;

        if ($compiled === null) {
            throw new \LogicException('A design that compiled on the transient variant did not compile on the template variant.');
        }

        $this->bus->dispatch(new EditTemplateVariantCanvasEditor($variantId, $compiled->canvasJson(), $compiled->textInputs, $compiled->imageInputs, previewImageDataUri: ''));
        $this->bus->dispatch(new StoreTemplateVariantPreviewImage(
            $variantId,
            $this->downscaleImage->toLongEdge($rendered, self::THUMBNAIL_LONG_EDGE, RenderImageFormat::Png)['contents'],
        ));

        if (!$this->transitions->accept($scan->id->toString(), $templateId->toString(), $variantId->toString())) {
            throw new \LogicException('A locked ready scan could not be accepted.');
        }

        $this->folders->rename($scan, $name);

        return $this->result($this->repository->get($scan->id), count($document['elements']), true);
    }

    /**
     * Places still empty point at the project's stand-in row — created only
     * when the template really has one.
     *
     * @param array{canvas: array<string, mixed>, elements: list<array<string, mixed>>} $document
     * @return array{canvas: array<string, mixed>, elements: list<array<string, mixed>>}
     */
    private function withStandInRow(AiScan $scan, array $document): array
    {
        $row = null;

        foreach ($document['elements'] as $index => $element) {
            if (($element['asset'] ?? null) !== self::STAND_IN) {
                continue;
            }

            if ($row === null) {
                $row = $this->standIn->projectFile($scan->project);
                // The compiler looks the row up: it has to exist.
                $this->entityManager->flush();
            }

            $document['elements'][$index]['asset'] = $row->id->toString();
        }

        return $document;
    }

    /**
     * @return array{templateId: null|string, variantId: null|string, elements: int, unfilledPictures: int, created: bool}
     */
    private function result(AiScan $scan, int $elements, bool $created): array
    {
        $variant = $scan->resultVariant;

        if ($variant !== null && !$created) {
            $canvas = json_decode($variant->canvas, true);
            $elements = is_array($canvas) && is_array($canvas['objects'] ?? null) ? count($canvas['objects']) : 0;
        }

        return [
            'templateId' => $scan->resultTemplate?->id->toString(),
            'variantId' => $variant?->id->toString(),
            'elements' => $elements,
            'unfilledPictures' => $variant === null ? 0 : $this->unfilledPictures->count($variant),
            'created' => $created,
        ];
    }
}
