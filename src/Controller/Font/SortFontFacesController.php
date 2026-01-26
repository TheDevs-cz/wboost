<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Font;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Font;
use WBoost\Web\Message\Font\SortFontFaces;
use WBoost\Web\Services\Security\FontVoter;

final class SortFontFacesController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/sort-font-faces/{fontId}', name: 'sort_font_faces')]
    #[IsGranted(FontVoter::EDIT, 'font')]
    public function __invoke(
        #[MapEntity(id: 'fontId')]
        Font $font,
        Request $request,
    ): JsonResponse
    {
        /** @var array{sorted?: array<string>} $data */
        $data = json_decode($request->getContent(), true);
        $sorted = $data['sorted'] ?? [];

        $this->bus->dispatch(
            new SortFontFaces($font->id, $sorted),
        );

        return new JsonResponse(['status' => 'success']);
    }
}
