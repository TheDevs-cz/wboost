<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\Project;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\Project\DeleteProject;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\Storage\CollectProjectStoragePaths;
use WBoost\Web\Services\Storage\DeleteProjectStorage;

#[AsMessageHandler]
readonly final class DeleteProjectHandler
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private CollectProjectStoragePaths $collectStoragePaths,
        private DeleteProjectStorage $deleteStorage,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Deleting a project takes its files with it.
     *
     * The DB rows always cascaded away, but the S3 objects never did, so every
     * deleted project left its manuals, backgrounds, previews, fonts and gallery
     * behind forever — unreachable, because the only record of which project
     * they belonged to was the rows that just vanished, and an image can never
     * be referenced from another project.
     *
     * Order is load-bearing:
     *  1. collect the paths WHILE the child rows still exist (most namespaces
     *     are keyed by manual / template / variant id, not by project id);
     *  2. remove + FLUSH, so the DELETE and its cascades actually execute and
     *     any constraint failure surfaces BEFORE a single byte is touched;
     *  3. only then delete from storage.
     *
     * That leaves one narrow window — a crash between the flush and the
     * transaction commit would roll the project back with its files already
     * gone. The alternative ordering is strictly worse (a failed flush would
     * leave a live project with no files), and the residual risk is a
     * connection loss at commit time.
     *
     * @throws ProjectNotFound
     */
    public function __invoke(DeleteProject $message): void
    {
        $project = $this->projectRepository->get($message->projectId);

        $paths = $this->collectStoragePaths->collect($project->id);

        $this->projectRepository->remove($project);
        $this->entityManager->flush();

        $this->deleteStorage->delete($paths);
    }
}
