<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\CustomTemplate;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Message\CustomTemplate\CopyCustomTemplate;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\CustomTemplateVoter;

final class DuplicateCustomTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/custom-template/{templateId}/copy', name: 'copy_custom_template')]
    #[IsGranted(CustomTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        CustomTemplate $template,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopyCustomTemplate(
                $template->id,
                $newId,
            ),
        );

        $this->addFlash('success', 'Šablona zduplikována.');

        return $this->redirectToRoute('custom_template_variants', [
            'templateId' => $newId,
        ]);
    }
}
