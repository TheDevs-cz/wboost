<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\AiScan\RateAiScan;
use WBoost\Web\Value\AiScan\AiScanRefusal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * A rating belongs to a proposal somebody saw: `ready` or `accepted`. The
 * last one counts. The note is the user's own words — stored, never logged.
 */
#[AsMessageHandler]
readonly final class RateAiScanHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @throws AiScanRefused
     */
    public function __invoke(RateAiScan $message): void
    {
        if (!in_array($message->rating, [1, -1], true)) {
            throw new AiScanRefused(AiScanRefusal::InvalidFix, ['field' => 'rating']);
        }

        $note = $message->note === null ? '' : trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $message->note) ?? '');

        $changed = $this->connection->executeStatement(
            "UPDATE ai_scan SET rating = :rating, rating_note = :note WHERE id = :id AND status IN ('ready', 'accepted')",
            [
                'id' => $message->scanId->toString(),
                'rating' => $message->rating,
                'note' => $note === '' || $message->rating === 1 ? null : mb_substr($note, 0, RateAiScan::NOTE_MAX),
            ],
        );

        if ($changed !== 1) {
            throw new AiScanRefused(AiScanRefusal::WrongState);
        }
    }
}
