<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Template\SaveTemplateFillDraft;
use WBoost\Web\Repository\TemplateExportVersionRepository;
use WBoost\Web\Services\Security\TemplateVariantVoter;
use WBoost\Web\Services\Template\ExportVersionTurboStream;
use WBoost\Web\Value\ExportFillValues;

/**
 * "Uložit rozpracované" on the single-variant fill page: stores the fill form
 * AS IT IS as a draft version in the export history — no render, no
 * validation beyond the normalisation every version gets, so it works while a
 * text overflows and the export is blocked. The page's own draft
 * (`draftVersionId`) is overwritten in place; anything else starts a new one.
 * Answered with a Turbo Stream re-rendering the history chrome around the
 * draft, plus its id in `X-Fill-Draft-Version` for the page's URL.
 */
final class SaveTemplateVariantFillDraftController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'template_fill_draft';

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private TemplateExportVersionRepository $versionRepository,
        readonly private ExportVersionTurboStream $turboStream,
        readonly private Security $security,
    ) {
    }

    #[Route(path: '/template-variant/{variantId}/fill-draft', name: 'template_variant_fill_draft', methods: ['POST'])]
    #[IsGranted(TemplateVariantVoter::VIEW, 'variant')]
    public function __invoke(
        #[MapEntity(id: 'variantId')]
        TemplateVariant $variant,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_fill_draft_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $draft = $this->versionRepository->findDraftOf($request->request->getString('draftVersionId'), $variant->id, null);
        $versionId = $draft->id ?? Uuid::uuid7();
        $user = $this->security->getUser();

        $this->bus->dispatch(new SaveTemplateFillDraft(
            $versionId,
            $variant->id,
            null,
            $user instanceof User ? $user->id : null,
            ExportFillValues::fromVariantWebForm(
                $request->request->all('textValues'),
                $request->request->all('hiddenValues'),
                $request->request->all('images'),
                $request->request->all('fontValues'),
                $request->request->all('shapeColorValues'),
            ),
        ));

        $response = $this->turboStream->draftSaved($this->versionRepository->get($versionId));
        $response->headers->set('X-Fill-Draft-Version', $versionId->toString());

        return $response;
    }
}
