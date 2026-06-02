<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Upload;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Exceptions\FileDirectoryNotFound;
use WBoost\Web\FormData\UploadProjectFileFormData;
use WBoost\Web\FormType\UploadProjectFileFormType;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

final class UploadFileController extends AbstractController
{
    public function __construct(
        readonly private FileUploadRepository $fileUploadRepository,
        readonly private FileDirectoryRepository $fileDirectoryRepository,
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(path: '/project/{projectId}/upload/{source}', name: 'project_upload_file')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
        FileSource $source,
    ): Response {
        $data = new UploadProjectFileFormData();
        $form = $this->createForm(UploadProjectFileFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $fileId = $this->provideIdentity->next();
            assert($data->file !== null);

            $this->bus->dispatch(
                new UploadFile(
                    $fileId,
                    $project->id,
                    $source,
                    $data->file,
                    $this->resolveDirectoryId($request, $project, $source),
                ),
            );

            $file = $this->fileUploadRepository->get($fileId);

            // `filePath` holds the public URL for backwards compatibility with
            // the editor controllers that already drop it onto a Fabric canvas
            // verbatim. `storagePath` is the raw S3 key — used by the Stage 7
            // image gallery to persist the chosen background on the variant.
            return $this->json([
                'filePath' => $this->uploaderHelper->getPublicPath($file->path),
                'storagePath' => $file->path,
            ]);
        }

        return $this->json(['error' => 'Invalid form submission'], 400);
    }

    /**
     * Resolve the optional `directoryId` request field into a directory the
     * upload should land in. Returns null (gallery root) when absent, invalid,
     * or pointing at a directory that does not belong to this project + source
     * — a defensive guard so an EDIT grant on one project can never drop files
     * into another project's folder.
     */
    private function resolveDirectoryId(Request $request, Project $project, FileSource $source): null|UuidInterface
    {
        $raw = (string) $request->request->get('directoryId', '');

        if ($raw === '' || !Uuid::isValid($raw)) {
            return null;
        }

        try {
            $directory = $this->fileDirectoryRepository->get(Uuid::fromString($raw));
        } catch (FileDirectoryNotFound) {
            return null;
        }

        // Source isolation is enforced by the single FileSource case today; when
        // FileSource gains more cases, also guard `$directory->source === $source`.
        if (!$directory->project->id->equals($project->id)) {
            return null;
        }

        return $directory->id;
    }
}
