<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\Security\ManualVoter;

final class ManualPreviewController extends AbstractController
{
    #[Route(path: '/manual/{id}/preview', name: 'manual_preview')]
    #[IsGranted(ManualVoter::VIEW, 'manual')]
    public function __invoke(Manual $manual): Response
    {
        return $this->render('manual_preview.html.twig', [
            'project' => $manual->project,
            'manual' => $manual,
        ]);
    }
}
