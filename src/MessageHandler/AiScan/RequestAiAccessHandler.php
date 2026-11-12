<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use WBoost\Web\Entity\AiAccessRequest;
use WBoost\Web\Exceptions\ProjectNotFound;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Message\AiScan\RequestAiAccess;
use WBoost\Web\Repository\ProjectRepository;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\AiScan\AiScanAccess;
use WBoost\Web\Services\AiScan\AiScanTime;
use WBoost\Web\Services\AiScan\AiScanUrls;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Stores the request and e-mails the admins' notification list with a link
 * to the project's switch.
 *
 * - **One open request per user and project**: a repeat answers with the
 *   open one and sends nothing (the project row is locked, and a partial
 *   unique index backs it).
 * - **Rate limit**: at most {@see MAX_PER_DAY} requests of one user in 24
 *   hours, over all projects — counted in the table, so it holds without a
 *   cache.
 * - Somebody who can already start scans there has nothing to ask for:
 *   `wrong_state`.
 */
#[AsMessageHandler]
readonly final class RequestAiAccessHandler
{
    public const int MAX_PER_DAY = 5;
    private const array ORIGINS = ['templates', 'calendars'];

    /**
     * @param list<string> $adminNotificationRecipients
     */
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private AiScanAccess $access,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private Environment $twig,
        private AiScanUrls $urls,
        private array $adminNotificationRecipients,
    ) {
    }

    /**
     * @return array{id: string, created: bool, sentAt: string}
     *
     * @throws ProjectNotFound
     * @throws UserNotFound
     * @throws AiScanRefused `wrong_state`, `rate_limited`
     */
    public function __invoke(RequestAiAccess $message): array
    {
        $projectId = $message->projectId->toString();
        $userId = $message->userId->toString();
        $now = $this->clock->now();

        $this->connection->executeQuery('SELECT id FROM project WHERE id = :id FOR UPDATE', ['id' => $projectId]);
        $project = $this->projectRepository->get($message->projectId);
        $user = $this->userRepository->getById($message->userId);

        if ($this->access->isEntitled($user, $project)) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }

        $open = $this->connection->fetchAssociative(
            "SELECT id, created_at FROM ai_access_request WHERE user_id = :user AND project_id = :project AND status = 'open'",
            ['user' => $userId, 'project' => $projectId],
        );

        if ($open !== false) {
            return [
                'id' => is_string($open['id']) ? $open['id'] : '',
                'created' => false,
                'sentAt' => (AiScanTime::fromSql(is_string($open['created_at']) ? $open['created_at'] : null) ?? $now)->format(DATE_ATOM),
            ];
        }

        $recent = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ai_access_request WHERE user_id = :user AND created_at >= :since',
            ['user' => $userId, 'since' => AiScanTime::sql($now->modify('-24 hours'))],
        );

        if (is_numeric($recent) && (int) $recent >= self::MAX_PER_DAY) {
            throw new AiScanRefused(AiScanRefusal::RateLimited);
        }

        $text = $message->message === null ? '' : trim(preg_replace('/[\\x00-\\x08\\x0B-\\x1F\\x7F]/u', '', $message->message) ?? '');

        $request = new AiAccessRequest(
            $message->requestId,
            $project,
            $user,
            $user->email,
            $user->getDisplayName(),
            in_array($message->origin, self::ORIGINS, true) ? $message->origin : self::ORIGINS[0],
            $text === '' ? null : mb_substr($text, 0, AiAccessRequest::MESSAGE_MAX),
            $now,
        );
        $this->entityManager->persist($request);

        if ($this->adminNotificationRecipients !== []) {
            $this->mailer->send((new Email())
                ->to(...$this->adminNotificationRecipients)
                ->subject(sprintf('Žádost o AI sken v projektu %s', $project->name))
                ->html($this->twig->render('emails/ai_access_request.html.twig', [
                    'userName' => $user->getDisplayName(),
                    'userEmail' => $user->email,
                    'projectName' => $project->name,
                    'origin' => $request->origin,
                    'message' => $request->message,
                    'projectUrl' => $this->urls->projectSwitch($project),
                ])));
        }

        return ['id' => $request->id->toString(), 'created' => true, 'sentAt' => $now->format(DATE_ATOM)];
    }
}
