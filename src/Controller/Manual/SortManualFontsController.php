<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Manual\SortManualFonts;
use WBoost\Web\Services\Security\ManualVoter;

final class SortManualFontsController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/sort-manual-fonts/{manualId}', name: 'sort_manual_fonts')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        Request $request,
    ): JsonResponse
    {
        /** @var array{sorted?: array<string>} $data */
        $data = json_decode($request->getContent(), true);
        $sorted = $data['sorted'] ?? [];

        $this->bus->dispatch(
            new SortManualFonts($sorted),
        );

        return new JsonResponse(['status' => 'success']);
    }
}
