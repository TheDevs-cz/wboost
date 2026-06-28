<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Project\ShareProject;
use WBoost\Web\Message\Project\UnshareProject;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Value\SharingLevel;

/**
 * Admin-only inline "Sdílení" manager mounted per project card on the projects
 * list. Adds/removes collaborators via the ShareProject/UnshareProject messages
 * and re-renders in place.
 *
 * Auth note: like {@see \WBoost\Web\Twig\Components\Project\ImageGallery},
 * #[IsGranted] can't sit at class level (the subject is a hydrated LiveProp), so
 * access is enforced in #[PostMount] + guard() at the top of every render method
 * and LiveAction.
 */
#[AsLiveComponent('ManageProjectSharing')]
final class ManageProjectSharingComponent extends AbstractController
{
    use DefaultActionTrait;

    #[LiveProp]
    public null|Project $project = null;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly UserRepository $userRepository,
    ) {
    }

    #[PostMount]
    public function postMount(): void
    {
        $this->guard();
    }

    /**
     * @return list<array{id: string, name: string, level: string}>
     */
    public function collaborators(): array
    {
        $project = $this->guard();

        $result = [];
        foreach ($project->getShares() as $share) {
            $result[] = [
                'id' => $share->user->id->toString(),
                'name' => $share->user->getDisplayName(),
                'level' => $share->level->value,
            ];
        }

        return $result;
    }

    /**
     * Every user who is neither the owner nor already a collaborator.
     *
     * @return list<array{id: string, name: string}>
     */
    public function candidateUsers(): array
    {
        $project = $this->guard();

        $excludedIds = [$project->owner->id->toString()];
        foreach ($project->getShares() as $share) {
            $excludedIds[] = $share->user->id->toString();
        }

        $result = [];
        foreach ($this->userRepository->findAll() as $user) {
            $id = $user->id->toString();
            if (in_array($id, $excludedIds, true)) {
                continue;
            }

            $result[] = ['id' => $id, 'name' => $user->getDisplayName()];
        }

        return $result;
    }

    #[LiveAction]
    public function share(#[LiveArg('userid')] string $userId): void
    {
        $project = $this->guard();

        if (!Uuid::isValid($userId)) {
            return;
        }

        $admin = $this->getUser();
        $sharedById = $admin instanceof User ? $admin->id->toString() : null;

        $this->bus->dispatch(new ShareProject(
            $project->id->toString(),
            $userId,
            SharingLevel::Read->value,
            $sharedById,
        ));
    }

    #[LiveAction]
    public function unshare(#[LiveArg('userid')] string $userId): void
    {
        $project = $this->guard();

        if (!Uuid::isValid($userId)) {
            return;
        }

        $this->bus->dispatch(new UnshareProject($project->id->toString(), $userId));
    }

    private function guard(): Project
    {
        $project = $this->project;
        assert($project !== null);

        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);

        return $project;
    }
}
