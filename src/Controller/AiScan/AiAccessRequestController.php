<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\AiScan;

use Ramsey\Uuid\Uuid;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\AiScan\RequestAiAccess;
use WBoost\Web\Message\AiScan\ResolveAiAccessRequest;
use WBoost\Web\Services\AiScan\AiAccessRequests;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

/**
 * "Požádat o přístup" (ux.md §9.1) and its resolution (§9.2).
 *
 * - `POST /project/{projectId}/ai-scan/access-request` (`origin`:
 *   `templates` | `calendars`, `message` ≤ 500 characters, optional) — a
 *   project editor. Stored and e-mailed to the admins. A second request while
 *   one is open answers with the open one (`created: false`). Refusals:
 *   `rate_limited` (five a day per user), `wrong_state` (the caller can
 *   already start scans here).
 * - `GET /admin/ai-scan/access-requests` (`?projectId=`) — the open requests.
 * - `POST /admin/ai-scan/access-request/{id}/{approve|dismiss}` — an admin;
 *   `approve` switches AI scan on for the project and e-mails the requester.
 *   A request that is no longer open: `wrong_state`.
 */
final class AiAccessRequestController extends AbstractController
{
    use AiScanResponses;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private AiAccessRequests $requests,
    ) {
    }

    #[Route(path: '/project/{projectId}/ai-scan/access-request', name: 'ai_scan_access_request', requirements: ['projectId' => '[0-9a-fA-F-]{36}'], methods: ['POST'])]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function request(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        $user = $this->getUser();
        assert($user instanceof User);
        $this->assertCsrf($request);

        $payload = $this->payload($request);

        $result = $this->attempt($this->bus, new RequestAiAccess(
            $this->provideIdentity->next(),
            $project->id,
            $user->id,
            is_string($payload['origin'] ?? null) ? $payload['origin'] : 'templates',
            is_string($payload['message'] ?? null) ? $payload['message'] : null,
        ));

        if ($result instanceof JsonResponse) {
            return $result;
        }

        assert(is_array($result));

        return new JsonResponse(['ok' => true] + $result + ['email' => $user->email], ($result['created'] ?? false) === true ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route(path: '/admin/ai-scan/access-requests', name: 'ai_scan_access_requests', methods: ['GET'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function open(Request $request): Response
    {
        $projectId = $request->query->getString('projectId');
        $response = new JsonResponse(['ok' => true, 'requests' => $this->requests->open(Uuid::isValid($projectId) ? $projectId : null)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route(
        path: '/admin/ai-scan/access-request/{id}/{action}',
        name: 'ai_scan_access_request_resolve',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'action' => 'approve|dismiss'],
        methods: ['POST'],
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function resolve(string $id, string $action, Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);
        $this->assertCsrf($request);

        $result = $this->attempt($this->bus, new ResolveAiAccessRequest(Uuid::fromString($id), $user->id, $action === 'approve'));

        return $result instanceof JsonResponse ? $result : new JsonResponse(['ok' => true, 'status' => $action === 'approve' ? 'approved' : 'dismissed']);
    }
}
