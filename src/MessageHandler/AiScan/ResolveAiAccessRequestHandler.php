<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Message\AiScan\ResolveAiAccessRequest;
use WBoost\Web\Message\AiScan\SetProjectAiScan;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Services\AiScan\AiScanTime;
use WBoost\Web\Services\AiScan\AiScanUrls;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Resolves an OPEN request, once (a conditional update — a second click is
 * `wrong_state`).
 *
 * `approve`: AI scan is switched on for the project through
 * {@see SetProjectAiScan} (its monthly limit stays as it is), every other
 * open request of that project is approved with it, and each requester gets
 * one e-mail ("AI sken je zapnutý" + "Vyzkoušet").
 */
#[AsMessageHandler]
readonly final class ResolveAiAccessRequestHandler
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private ProjectRepository $projectRepository,
        private MailerInterface $mailer,
        private Environment $twig,
        private AiScanUrls $urls,
    ) {
    }

    /**
     * @throws AiScanRefused `wrong_state`
     * @throws ProjectNotFound
     */
    public function __invoke(ResolveAiAccessRequest $message): void
    {
        $now = AiScanTime::sql($this->clock->now());
        $resolved = ['now' => $now, 'admin' => $message->adminId->toString()];

        $projectId = $this->connection->fetchOne(
            "UPDATE ai_access_request SET status = :status, resolved_at = :now, resolved_by_id = :admin
              WHERE id = :id AND status = 'open' RETURNING project_id",
            ['id' => $message->requestId->toString(), 'status' => $message->approve ? 'approved' : 'dismissed'] + $resolved,
        );

        if (!is_string($projectId)) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        if (!$message->approve) {
            return;
        }

        $this->connection->executeStatement(
            "UPDATE ai_access_request SET status = 'approved', resolved_at = :now, resolved_by_id = :admin WHERE project_id = :project AND status = 'open'",
            ['project' => $projectId] + $resolved,
        );

        $project = $this->projectRepository->get(Uuid::fromString($projectId));
        $this->bus->dispatch(new SetProjectAiScan($project->id, true, $project->aiScanMonthlyLimit, $message->adminId));

        /** @var list<string> $recipients */
        $recipients = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT requester_email FROM ai_access_request WHERE project_id = :project AND status = 'approved' AND resolved_at = :now AND user_id IS NOT NULL",
            ['project' => $projectId, 'now' => $now],
        );

        foreach ($recipients as $recipient) {
            $this->mailer->send((new Email())
                ->to($recipient)
                ->subject('AI sken je zapnutý')
                ->html($this->twig->render('emails/ai_access_granted.html.twig', [
                    'projectName' => $project->name,
                    'url' => $this->urls->templates($project),
                ])));
        }
    }
}
