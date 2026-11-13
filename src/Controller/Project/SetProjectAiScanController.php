<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Project;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Message\AiScan\SetProjectAiScan;

/**
 * The admin's per-project AI scan switch on the edit-project page: the
 * entitlement and the monthly limit (empty = no limit). `ROLE_ADMIN` only.
 */
final class SetProjectAiScanController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'project_ai_scan';

    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/project/{projectId}/ai-scan/entitlement', name: 'project_ai_scan_set', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(
        #[MapEntity(id: 'projectId')]
        Project $project,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        $enabled = $request->request->has('enabled') ? $request->request->getBoolean('enabled') : $project->aiScanEnabled;
        $limit = trim($request->request->getString('monthly_limit', (string) ($project->aiScanMonthlyLimit ?? '')));

        $this->bus->dispatch(new SetProjectAiScan(
            $project->id,
            $enabled,
            $limit === '' || !ctype_digit($limit) ? null : min((int) $limit, 100000),
            $user->id,
        ));

        if (!$request->request->has('enabled')) {
            $this->addFlash('success', 'Měsíční limit AI skenu uložen.');
        } elseif ($enabled) {
            $this->addFlash('success', sprintf('AI sken zapnut pro projekt %s.', $project->name));
        } else {
            $this->addFlash('success', 'AI sken vypnut. Čekající skeny jsme zrušili a nezapočítali.');
        }

        // The same switch sits on the admin's user page (ux.md §9.3): it names
        // the user to come back to — an id, never a URL.
        $returnUser = $request->request->getString('return_user');

        if (Uuid::isValid($returnUser)) {
            return $this->redirect($this->generateUrl('admin_edit_user', ['id' => $returnUser]) . '#user-projects');
        }

        // … and on the admin tab „AI skeny“ (ux.md §9.4) — a fixed word, never a URL.
        if ($request->request->getString('return_to') === 'admin_ai_scans') {
            return $this->redirect($this->generateUrl('admin_usage') . '#ai-skeny');
        }

        return $this->redirect($this->generateUrl('edit_project', ['id' => $project->id->toString()]) . '#project-ai-scan');
    }
}
