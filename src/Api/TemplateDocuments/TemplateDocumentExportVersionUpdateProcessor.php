<?php

declare(strict_types=1);

namespace WBoost\Web\Api\TemplateDocuments;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Api\ExportVersions\ExportVersionUpdateRequest;
use WBoost\Web\Message\TemplateDocument\PinTemplateDocumentExportVersion;
use WBoost\Web\Message\TemplateDocument\RenameTemplateDocumentExportVersion;
use WBoost\Web\Repository\TemplateDocumentExportVersionRepository;

/**
 * `PATCH /api/template-document-export-versions/{versionId}` — curate the
 * document history: `{ "name": "…" | null }` renames, `{ "pinned": bool }`
 * pins / unpins; absent keys are left alone (the variant PATCH contract).
 *
 * @implements ProcessorInterface<ExportVersionUpdateRequest, TemplateDocumentExportVersionDetailResponse>
 */
final readonly class TemplateDocumentExportVersionUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private TemplateDocumentExportVersionProvider $versionProvider,
        private MessageBusInterface $bus,
        private TemplateDocumentExportVersionRepository $versionRepository,
    ) {
    }

    /**
     * @param ExportVersionUpdateRequest $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TemplateDocumentExportVersionDetailResponse
    {
        $version = $this->versionProvider->load($uriVariables['versionId'] ?? null);

        // A null `name` means "clear it", an absent one "leave it" — only the
        // raw body tells the two apart.
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? json_decode($request->getContent(), true) : null;
        $provided = is_array($body) ? $body : [];

        if (!array_key_exists('name', $provided) && !array_key_exists('pinned', $provided)) {
            throw new BadRequestHttpException('Provide "name" and/or "pinned".');
        }

        if (array_key_exists('pinned', $provided) && !is_bool($provided['pinned'])) {
            throw new BadRequestHttpException('"pinned" must be a boolean.');
        }

        if (array_key_exists('name', $provided)) {
            $this->bus->dispatch(new RenameTemplateDocumentExportVersion($version->id, $data->name));
        }

        if (array_key_exists('pinned', $provided)) {
            $this->bus->dispatch(new PinTemplateDocumentExportVersion($version->id, (bool) $provided['pinned']));
        }

        return $this->versionProvider->present($this->versionRepository->get($version->id));
    }
}
