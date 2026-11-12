<?php

declare(strict_types=1);

namespace WBoost\Web\Value\AiScan;

/**
 * An expected "no" to a user's start / retry / continue / cancel / discard.
 * Never an HTTP exception: the controllers turn it into a JSON answer with
 * the code, so it books no Sentry issue.
 */
final class AiScanRefused extends \DomainException
{
    /**
     * @param array<string, int|string> $params
     */
    public function __construct(
        public readonly AiScanRefusal $refusal,
        public readonly array $params = [],
    ) {
        parent::__construct('AI scan refused: ' . $refusal->value);
    }
}
