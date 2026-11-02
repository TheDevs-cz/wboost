<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\RegistrationRequest;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetProjectAccess;
use WBoost\Web\Query\GetUsersOverview;
use WBoost\Web\Query\UserOverviewRow;
use WBoost\Web\Repository\RegistrationRequestRepository;
use WBoost\Web\Value\RegistrationRequestStatus;

final class AdminUsersController extends AbstractController
{
    public function __construct(
        readonly private GetUsersOverview $getUsersOverview,
        readonly private GetProjectAccess $getProjectAccess,
        readonly private RegistrationRequestRepository $registrationRequestRepository,
    ) {
    }

    #[Route(path: '/admin/users', name: 'admin_users')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(
        #[CurrentUser]
        User $admin,
    ): Response {
        $currentUserId = $admin->id->toString();

        // Registration requests sit inline at the top of the list (pending)
        // and under the "Zamítnuté" filter (dismissed). Invited ones already
        // exist as (pending) users, so they are not repeated.
        $requests = array_values(array_filter(
            $this->registrationRequestRepository->allPendingFirst(),
            static fn (RegistrationRequest $request): bool => $request->status !== RegistrationRequestStatus::Invited,
        ));

        return $this->render('admin/users.html.twig', [
            'users' => $this->ordered($this->getUsersOverview->all(), $currentUserId),
            'requests' => $requests,
            'access' => $this->getProjectAccess->byUser(),
            'currentUserId' => $currentUserId,
        ]);
    }

    /**
     * Prototype order: me, then pending invitations, then everyone else A–Z
     * (Czech collation, by the name shown in the row).
     *
     * @param list<UserOverviewRow> $users
     * @return list<UserOverviewRow>
     */
    private function ordered(array $users, string $currentUserId): array
    {
        $collator = new \Collator('cs_CZ');
        $rank = static fn (UserOverviewRow $user): int => match (true) {
            $user->id === $currentUserId => 0,
            $user->isPending() => 1,
            default => 2,
        };

        usort($users, static fn (UserOverviewRow $a, UserOverviewRow $b): int => ($rank($a) <=> $rank($b))
            ?: (int) $collator->compare($a->displayName(), $b->displayName()));

        return $users;
    }
}
