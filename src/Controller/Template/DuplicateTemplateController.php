<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Template;
use WBoost\Web\Message\Template\CopyTemplate;
use WBoost\Web\Repository\TemplateRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\Security\TemplateVoter;

final class DuplicateTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private TemplateRepository $templateRepository,
    ) {
    }

    #[Route(path: '/template/{templateId}/copy', name: 'copy_template')]
    #[IsGranted(TemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        Template $template,
    ): Response {
        // A synchronized template is copied into a NEW group whose design is
        // only editable in the group editor — the same designer gate as
        // editing the original's group.
        if ($template->group !== null) {
            $this->denyAccessUnlessGranted(TemplateGroupVoter::EDIT, $template->group);
        }

        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopyTemplate(
                $template->id,
                $newId,
            ),
        );

        $this->addFlash('success', 'Šablona zduplikována.');

        $newGroup = $this->templateRepository->get($newId)->group;

        if ($newGroup !== null) {
            return $this->redirectToRoute('template_group_editor', [
                'groupId' => $newGroup->id,
            ]);
        }

        return $this->redirectToRoute('template_variants', [
            'templateId' => $newId,
        ]);
    }
}
