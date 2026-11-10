<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Services\Manual\ManualContentPageEditor;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;

/**
 * Saves the whole intro page in one POST: `payload` = the editor's JSON
 * document (`{name, layout, segments}`), `_token` = CSRF. Answers JSON —
 * the editor stays where it is and takes the normalised state (fonts / colours the manual no longer
 * allows are dropped server-side), so its dirty tracking compares against
 * what was really stored. Addressed by the manual: the intro page is
 * created by its first save.
 */
final class SaveManualIntroPageController extends AbstractController
{
    public function __construct(
        readonly private ManualContentPageEditor $editor,
        readonly private ManualContentPageRepository $contentPageRepository,
    ) {
    }

    #[Route(path: '/manual/{manualId}/uvodni-stranka', name: 'manual_intro_page_save', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        Request $request,
    ): Response {
        return $this->editor->save(
            $request,
            $manual,
            ManualContentPageRole::Intro,
            $this->contentPageRepository->introFor($manual->id)->id ?? Uuid::uuid7(),
        );
    }
}
