<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Query\GetAiUsageOverview;
use WBoost\Web\Query\GetStorageOverview;
use WBoost\Web\Query\GetUsageOverview;
use WBoost\Web\Query\GetUserActivity;

final class AdminUsageController extends AbstractController
{
    public function __construct(
        readonly private GetUsageOverview $getUsageOverview,
        readonly private GetUserActivity $getUserActivity,
        readonly private GetStorageOverview $getStorageOverview,
        readonly private GetAiUsageOverview $getAiUsageOverview,
    ) {
    }

    #[Route(path: '/admin/usage', name: 'admin_usage')]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(): Response
    {
        return $this->render('admin/usage.html.twig', [
            'usage' => $this->getUsageOverview->overview(),
            'activity' => $this->getUserActivity->overview(),
            'storage' => $this->getStorageOverview->overview(),
            // The fourth tab, „AI skeny“ (ux.md §9.4).
            'ai' => $this->getAiUsageOverview->overview(),
        ]);
    }
}
