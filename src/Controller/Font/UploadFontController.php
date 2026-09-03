<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Font;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FontAlreadyHasFontFace;
use WBoost\Web\Exceptions\FontNotFound;
use WBoost\Web\Exceptions\UnsupportedFontFile;
use WBoost\Web\FormData\UploadFontFormData;
use WBoost\Web\FormType\UploadFontFormType;
use WBoost\Web\Message\Font\AddFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * JSON endpoint behind the fonts page dropzone (and the manual fonts page's
 * one): one file per request, answered with what happened to it — the family
 * it was filed under and the face it became, "already uploaded", or the
 * refusal reason (WOFF2, unreadable). 200 for added / already-there so the
 * batch keeps rolling; 422 for a refused file.
 */
final class UploadFontController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private GetFonts $getFonts,
    ) {
    }

    #[Route(path: '/project/{id}/fonts/upload', name: 'upload_font', methods: ['POST'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(Project $project, Request $request): JsonResponse
    {
        $data = new UploadFontFormData();
        $form = $this->createForm(UploadFontFormType::class, $data);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid() || $data->file === null) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getMessage();
            }

            return $this->json(['error' => $errors[0] ?? 'Neplatný požadavek.'], Response::HTTP_BAD_REQUEST);
        }

        $fileName = $data->file->getClientOriginalName();

        try {
            $this->bus->dispatch(new AddFont($project->id, $data->file));
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof FontAlreadyHasFontFace) {
                return $this->json(['status' => 'exists', 'file' => $fileName, 'message' => 'Tento řez už je nahraný.']);
            }

            if ($previous instanceof UnsupportedFontFile) {
                return $this->json(['status' => 'unsupported', 'file' => $fileName, 'error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $previous ?? $exception;
        }

        return $this->json(['status' => 'added', 'file' => $fileName] + $this->describeFace($project, $fileName));
    }

    /**
     * The family + face the file just became: the newest face across the
     * project's fonts (the handler names it from the file's own name table,
     * which the controller does not see).
     *
     * @return array{family: null|string, face: null|string, faces: int}
     */
    private function describeFace(Project $project, string $fileName): array
    {
        $stem = pathinfo($fileName, PATHINFO_FILENAME);
        $newest = null;

        try {
            foreach ($this->getFonts->allForProject($project->id) as $font) {
                foreach ($font->faces as $face) {
                    // The stored file name is "<face>-<timestamp>.<ext>"; the
                    // newest timestamp is the face this request created.
                    if (preg_match('/-(\d+)\.[a-z0-9]+$/i', $face->filePath, $matches) !== 1) {
                        continue;
                    }
                    $stamp = (int) $matches[1];
                    if ($newest === null || $stamp >= $newest['stamp']) {
                        $newest = ['stamp' => $stamp, 'family' => $font->name, 'face' => $face->name, 'faces' => count($font->faces)];
                    }
                }
            }
        } catch (FontNotFound) {
            // Nothing to describe — the file name is the best label we have.
        }

        return [
            'family' => $newest['family'] ?? $stem,
            'face' => $newest['face'] ?? $stem,
            'faces' => $newest['faces'] ?? 1,
        ];
    }
}
