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
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Project\ShareProject;
use WBoost\Web\Message\Project\UnshareProject;
use WBoost\Web\Message\User\InviteUser;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Value\SharingLevel;

/**
 * Admin-only inline "Sdílení" manager mounted per project card on the projects
 * list. Adds/removes collaborators via the ShareProject/UnshareProject messages
 * and re-renders in place.
 *
 * The search field doubles as the invite field: typing an e-mail offers
 * "Pozvat" ({@see invite()}) — a registered user is simply shared, an unknown
 * address gets a pending WBoost account + invitation e-mail with this project
 * pre-shared (the admin InviteUser flow), a pending one is re-invited.
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

    /** The "Jméno nebo e-mail" field — filters candidates client-side, read by invite(). */
    #[LiveProp(writable: true)]
    public string $query = '';

    /** One-render feedback after invite() (not a LiveProp: gone on the next render). */
    public null|string $notice = null;

    public null|string $error = null;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
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
     * @return list<array{id: string, name: string, email: string}>
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

            $result[] = ['id' => $id, 'name' => $user->getDisplayName(), 'email' => $user->email];
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
    public function invite(): void
    {
        $project = $this->guard();
        $email = mb_strtolower(trim($this->query));

        if ($email === '' || count($this->validator->validate($email, new Email(mode: Email::VALIDATION_MODE_STRICT))) > 0) {
            $this->error = 'Zadejte platný e-mail.';

            return;
        }

        $admin = $this->getUser();
        assert($admin instanceof User);
        $existing = $this->userRepository->findByEmailCaseInsensitiveOrNull($email);

        if ($existing !== null && $existing->id->equals($project->owner->id)) {
            $this->error = sprintf('%s je vlastník projektu.', $existing->email);

            return;
        }

        if ($existing !== null && $project->getUserSharingLevel($existing) !== null && $existing->confirmed) {
            $this->error = sprintf('%s už má do projektu přístup.', $existing->email);

            return;
        }

        if ($existing !== null && $existing->confirmed) {
            $this->bus->dispatch(new ShareProject(
                $project->id->toString(),
                $existing->id->toString(),
                SharingLevel::Read->value,
                $admin->id->toString(),
            ));
            $this->notice = sprintf('%s už WBoost používá — projekt má nyní nasdílený.', $existing->email);
        } else {
            // New address, or a pending invitee (re-invited: fresh link, this
            // project added, their stored role kept). New people start as a
            // plain "Uživatel" — admins promote them in Správa uživatelů.
            $this->bus->dispatch(new InviteUser(
                $existing->email ?? $email,
                null,
                $existing !== null ? array_values(array_diff($existing->getRoles(), ['ROLE_USER'])) : [],
                [$project->id->toString()],
                $admin->id->toString(),
            ));
            $this->notice = sprintf('Pozvánka do WBoost odeslána na %s, projekt je mu nasdílený.', $existing->email ?? $email);
        }

        $this->query = '';
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
