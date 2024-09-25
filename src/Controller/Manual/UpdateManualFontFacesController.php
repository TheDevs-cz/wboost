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
use WBoost\Web\Entity\ManualFont;
use WBoost\Web\Message\Manual\UpdateManualFontFaces;
use WBoost\Web\Services\Security\ManualFontVoter;

final class UpdateManualFontFacesController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/update-manual-font-faces/{manualFontId}', name: 'update_manual_font_faces', methods: ['POST'])]
    #[IsGranted(ManualFontVoter::EDIT, 'manualFont')]
    public function __invoke(
        #[MapEntity(id: 'manualFontId')]
        ManualFont $manualFont,
        Request $request,
    ): JsonResponse
    {
        /** @var array{fontFaces?: array<string>} $data */
        $data = json_decode($request->getContent(), true);
        $fontFaces = $data['fontFaces'] ?? [];

        $this->bus->dispatch(
            new UpdateManualFontFaces($manualFont->id, $fontFaces),
        );

        return new JsonResponse(['status' => 'success']);
    }
}
