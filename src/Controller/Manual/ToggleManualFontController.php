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
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Manual\DisableManualFont;
use WBoost\Web\Message\Manual\EnableManualFont;
use WBoost\Web\Services\Security\ManualVoter;

final class ToggleManualFontController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{id}/fonts/{fontId}/enable', name: 'enable_manual_font')]
    #[Route(path: '/manual/{id}/fonts/{fontId}/disable', name: 'disable_manual_font')]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        Request $request,
        Manual $manual,
        #[MapEntity(id: 'fontId')]
        Font $font,
    ): Response {
        /** @var string $routeName */
        $routeName = $request->attributes->get('_route');

        // Because someone creative might have entered font from different project
        if ($font->project !== $manual->project) {
            throw $this->createAccessDeniedException();
        }

        if ($routeName === 'enable_manual_font') {
            $this->bus->dispatch(
                new EnableManualFont(
                    $manual->id,
                    $font->id,
                ),
            );
        }

        if ($routeName === 'disable_manual_font') {
            $this->bus->dispatch(
                new DisableManualFont(
                    $manual->id,
                    $font->id,
                ),
            );
        }

        return $this->redirectToRoute('manual_fonts', [
            'id' => $manual->id->toString(),
        ]);
    }
}
