<?php

declare(strict_types=1);

namespace WBoost\Web\Api\ExportVersions;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Message\Template\PinTemplateExportVersion;
use WBoost\Web\Message\Template\RenameTemplateExportVersion;
use WBoost\Web\Repository\TemplateExportVersionRepository;

/**
 * `PATCH /api/export-versions/{versionId}` — curate the shared history:
 * `{ "name": "…" | null }` renames, `{ "pinned": true|false }` pins / unpins.
 * Keys that are absent are left alone. Same messages (and the same rules —
 * trim, 120-char cut, re-pin keeps its time) as the web history.
 *
 * @implements ProcessorInterface<ExportVersionUpdateRequest, ExportVersionDetailResponse>
 */
final readonly class ExportVersionUpdateProcessor implements ProcessorInterface
{
    public function __construct(
        private ExportVersionProvider $versionProvider,
        private MessageBusInterface $bus,
        private TemplateExportVersionRepository $versionRepository,
    ) {
    }

    /**
     * @param ExportVersionUpdateRequest $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExportVersionDetailResponse
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

        if (array_key_exists('name', $provided)) {
            $this->bus->dispatch(new RenameTemplateExportVersion($version->id, $data->name));
        }

        if (array_key_exists('pinned', $provided)) {
            if (!is_bool($provided['pinned'])) {
                throw new BadRequestHttpException('"pinned" must be a boolean.');
            }

            $this->bus->dispatch(new PinTemplateExportVersion($version->id, $provided['pinned']));
        }

        return $this->versionProvider->present($this->versionRepository->get($version->id));
    }
}
