<?php

declare(strict_types=1);

namespace WBoost\Web\Api\Templates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use WBoost\Web\Exceptions\InvalidPrintSetup;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\Template\ApiSubjectLoader;
use WBoost\Web\Services\Template\VariantPrintSetup;

/**
 * `PATCH /api/template-variants/{variantId}/print-setup` — the web
 * "Nastavení tisku" over the API ({@see VariantPrintSetup}). Same access
 * ladder as the other variant endpoints (400 bad id / 404 unknown / 403 not
 * visible) plus EDIT on the variant, like the web form.
 *
 * @implements ProcessorInterface<TemplateVariantPrintSetupRequest, TemplateVariantPrintSetupResponse|Response>
 */
final readonly class TemplateVariantPrintSetupProcessor implements ProcessorInterface
{
    public function __construct(
        private ApiSubjectLoader $subjectLoader,
        private Security $security,
        private VariantPrintSetup $printSetup,
        private TemplateVariantRepository $variantRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TemplateVariantPrintSetupResponse|Response
    {
        $variant = $this->subjectLoader->variant($uriVariables['variantId'] ?? null);

        if (!$this->security->isGranted(TemplateVariantVoter::EDIT, $variant)) {
            throw new AccessDeniedHttpException('Changing the print setup needs edit rights on the template.');
        }

        // Absent = keep; only the raw body tells absent from null.
        $request = $context['request'] ?? null;
        $body = $request instanceof Request ? json_decode($request->getContent(), true) : null;

        try {
            $this->printSetup->change($variant, is_array($body) ? $body : []);
        } catch (InvalidPrintSetup $refused) {
            return new JsonResponse([
                'error' => $refused->getMessage(),
                'code' => $refused->reason,
                ...($refused->documentId !== null ? ['documentId' => $refused->documentId] : []),
            ], Response::HTTP_BAD_REQUEST);
        }

        $dimension = $this->variantRepository->get($variant->id)->dimension;

        return new TemplateVariantPrintSetupResponse(
            id: $variant->id->toString(),
            unit: $dimension->unit->value,
            printFormat: $dimension->isPrintFormat(),
            bleedMm: $dimension->bleedMm,
            bleedPx: $dimension->bleedPixels(),
            cropMarks: $dimension->cropMarks,
        );
    }
}
