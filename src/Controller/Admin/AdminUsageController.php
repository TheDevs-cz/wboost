<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
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
    public function __invoke(Request $request): Response
    {
        return $this->render('admin/usage.html.twig', [
            'usage' => $this->getUsageOverview->overview(),
            'activity' => $this->getUserActivity->overview(),
            'storage' => $this->getStorageOverview->overview(),
            // The fourth tab, „AI skeny“ (ux.md §9.4). Its scans table reads three
            // query parameters — a URL contract other pages link to (always with
            // `#ai-skeny`, which opens the tab):
            //   ?ai_scan=<scan uuid>       that scan only, with every usage row of it
            //   ?ai_project=<project uuid> that project's scans only (paged)
            //   ?ai_page=<n>               the page of ended scans, 1-based
            // Anything that is not a uuid / a positive number is ignored.
            'ai' => $this->getAiUsageOverview->overview(
                self::page($request->query->all()['ai_page'] ?? null),
                self::uuid($request->query->all()['ai_scan'] ?? null),
                self::uuid($request->query->all()['ai_project'] ?? null),
            ),
        ]);
    }

    private static function page(mixed $value): int
    {
        return is_string($value) && ctype_digit($value) && strlen($value) <= 6 ? max(1, (int) $value) : 1;
    }

    private static function uuid(mixed $value): null|string
    {
        return is_string($value) && Uuid::isValid($value) ? strtolower($value) : null;
    }
}
