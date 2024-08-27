<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Upload;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\ManualFormData;
use WBoost\Web\FormData\UploadProjectFileFormData;
use WBoost\Web\FormType\ManualFormType;
use WBoost\Web\FormType\UploadProjectFileFormType;
use WBoost\Web\Message\Image\UploadFile;
use WBoost\Web\Message\Manual\AddManual;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Value\FileSource;

final class UploadFileController extends AbstractController
{
    public function __construct(
        readonly private FileUploadRepository $fileUploadRepository,
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
                ),
            );

            $file = $this->fileUploadRepository->get($fileId);

            return $this->json(['filePath' => $this->uploaderHelper->getPublicPath($file->path)]);
        }

        return $this->json(['error' => 'Invalid form submission'], 400);
    }
}
