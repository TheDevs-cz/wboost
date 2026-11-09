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
use WBoost\Web\Message\Manual\SortManualPages;
use WBoost\Web\Services\Security\ManualVoter;

/**
 * The Stránky list's drag & drop: `{sorted: ["mockup:<uuid>", "content:<uuid>", …]}`
 * — mockup pages and custom content pages in one shared order.
 */
final class SortManualPagesController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{manualId}/stranky/poradi', name: 'sort_manual_pages', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        Request $request,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $sorted = is_array($data) && is_array($data['sorted'] ?? null) ? $data['sorted'] : [];

        $this->bus->dispatch(new SortManualPages($manual->id, $sorted));

        return new JsonResponse(['status' => 'success']);
    }
}
