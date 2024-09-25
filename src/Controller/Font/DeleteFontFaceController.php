<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Font;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Font;
use WBoost\Web\Message\Font\DeleteFont;
use WBoost\Web\Message\Font\DeleteFontFace;
use WBoost\Web\Services\Security\FontVoter;

final class DeleteFontFaceController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/delete-font/{fontId}/face/{fontFaceName}', name: 'delete_font_face')]
    #[IsGranted(FontVoter::EDIT, 'font')]
    public function __invoke(
        #[MapEntity(id: 'fontId')]
        Font $font,
        string $fontFaceName,
    ): Response {
        $this->bus->dispatch(
            new DeleteFontFace($font->id, $fontFaceName),
        );

        $this->addFlash('success', 'Řez font smazán!');

        return $this->redirectToRoute('fonts_list', [
            'id' => $font->project->id,
        ]);
    }
}
