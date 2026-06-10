<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Flyer;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\FlyerTemplate;
use WBoost\Web\Message\Flyer\CopyFlyerTemplate;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\FlyerTemplateVoter;

final class DuplicateFlyerTemplateController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/flyer-template/{templateId}/copy', name: 'copy_flyer_template')]
    #[IsGranted(FlyerTemplateVoter::EDIT, 'template')]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        FlyerTemplate $template,
    ): Response {
        $newId = $this->provideIdentity->next();

        $this->bus->dispatch(
            new CopyFlyerTemplate(
                $template->id,
                $newId,
            ),
        );

        $this->addFlash('success', 'Šablona zduplikována.');

        return $this->redirectToRoute('flyer_template_variants', [
            'templateId' => $newId,
        ]);
    }
}
