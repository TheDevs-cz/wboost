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
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\TemplateVoter;

final class DuplicateTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/template/{templateId}/copy', name: 'copy_template')]
    #[IsGranted(TemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        Template $template,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopyTemplate(
                $template->id,
                $newId,
            ),
        );

        $this->addFlash('success', 'Šablona zduplikována.');

        return $this->redirectToRoute('template_variants', [
            'templateId' => $newId,
        ]);
    }
}
