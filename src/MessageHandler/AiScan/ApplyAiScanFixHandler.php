<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\AiScan;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Message\AiScan\ApplyAiScanFix;
use WBoost\Web\Services\AiScan\Template\ScanProposal;
use WBoost\Web\Value\AiScan\AiScanRefused;

/**
 * Saves the fix, then renders the proposal once so its picture stays true.
 * Both under the scan's row lock, in the bus's transaction: two tabs fixing
 * the same proposal are serialised, the last change of an item wins.
 *
 * A renderer that is busy or away does not undo the fix — it is saved and
 * the answer says `previewStale: true`.
 */
#[AsMessageHandler]
readonly final class ApplyAiScanFixHandler
{
    public function __construct(
        private ScanProposal $proposal,
    ) {
    }

    /**
     * @return array{previewStale: bool, assigned: null|int}
     *
     * @throws AiScanRefused
     */
    public function __invoke(ApplyAiScanFix $message): array
    {
        $facts = ['assigned' => null];

        if ($message->fix !== null) {
            $facts = $this->proposal->apply($message->scanId, $message->fix, $message->uploadedFileIds);
        }

        return ['previewStale' => !$message->render || !$this->proposal->render($message->scanId), 'assigned' => $facts['assigned']];
    }
}
