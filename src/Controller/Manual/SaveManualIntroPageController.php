<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Message\Manual\SaveManualContentPage;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Services\Manual\ManualContentEditorState;
use WBoost\Web\Services\Manual\ResolveManualContentStyles;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\ManualContent\ManualContentPagePayload;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;

/**
 * Saves the whole intro page in one POST: `payload` = the editor's JSON
 * document (`{name, layout, segments}`), `_token` = CSRF. Answers JSON —
 * the editor stays where it is and swaps in the fresh "Náhled v manuálu"
 * render plus the normalised state (fonts / colours the manual no longer
 * allows are dropped server-side), so its dirty tracking compares against
 * what was really stored.
 */
final class SaveManualIntroPageController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ManualContentPageRepository $contentPageRepository,
        readonly private ResolveManualContentStyles $resolveStyles,
        readonly private ManualContentEditorState $editorState,
    ) {
    }

    #[Route(path: '/manual/{manualId}/uvodni-stranka', name: 'manual_intro_page_save', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(ManualIntroPageController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            return new JsonResponse(['error' => 'Platnost formuláře vypršela. Obnovte stránku a uložte znovu.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $payload = ManualContentPagePayload::fromJson($request->request->getString('payload'));

            $this->bus->dispatch(new SaveManualContentPage(
                $manual->id,
                $this->contentPageRepository->introFor($manual->id)->id ?? Uuid::uuid7(),
                ManualContentPageRole::Intro,
                $payload->name,
                $payload->layout,
                $payload->segments,
            ));
        } catch (InvalidManualContent $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HandlerFailedException $failed) {
            $real = $failed->getPrevious();

            if (!$real instanceof InvalidManualContent) {
                throw $failed;
            }

            return new JsonResponse(['error' => $real->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $introPage = $this->contentPageRepository->introFor($manual->id);
        $styles = $this->resolveStyles->forManual($manual);

        return new JsonResponse([
            'state' => $this->editorState->forIntro($manual, $introPage),
            'preview' => $this->renderView('_manual_intro_page_preview.html.twig', [
                'manual' => $manual,
                'intro_page' => $introPage,
                'content_styles' => $styles,
            ]),
        ]);
    }
}
