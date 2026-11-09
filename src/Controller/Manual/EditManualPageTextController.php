<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\ManualPageTextFormData;
use WBoost\Web\Message\Manual\EditManualPageText;
use WBoost\Web\Services\Manual\ManualEditForms;
use WBoost\Web\Services\Manual\ManualPreviewTurboStream;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\ManualPage;

/**
 * Saves the pencil modal next to a manual page's heading (its title and
 * description, `ManualPageText` component). From the preview the form is
 * Turbo-enabled: the answer is a Turbo Stream that swaps the heading's title
 * and the description in place (and shows validation errors inside the
 * modal). A plain POST — no JS — is redirected back to the edited heading.
 */
final class EditManualPageTextController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ManualEditForms $forms,
        readonly private ManualPreviewTurboStream $turboStream,
    ) {
    }

    #[Route(path: '/manual/{manualId}/texty-stranky/{page}', name: 'manual_page_text_edit', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        ManualPage $page,
        Request $request,
    ): Response {
        $form = $this->forms->pageText($manual, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var ManualPageTextFormData $data */
            $data = $form->getData();

            $this->bus->dispatch(new EditManualPageText($manual->id, $page, $data->title, $data->description));

            if (ManualPreviewTurboStream::wants($request)) {
                return $this->turboStream->pageTextSaved($request, $manual, $page);
            }
        } elseif (ManualPreviewTurboStream::wants($request)) {
            return $this->turboStream->pageTextInvalid($request, $page, $form);
        }

        return $this->redirect($this->generateUrl('manual_preview', [
            'projectSlug' => $manual->project->slug,
            'manualSlug' => $manual->slug,
        ]) . '#' . ManualPreviewTurboStream::titleId($page));
    }
}
