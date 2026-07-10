<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * Base error for Meta Graph API calls (Facebook + Instagram publishing).
 * Subclasses map well-known Graph error codes to actionable user messages;
 * this base covers everything else.
 */
class MetaApiError extends \Exception
{
    public function __construct(
        string $message,
        public readonly null|int $graphCode = null,
        public readonly null|int $graphSubcode = null,
        null|\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function userMessage(): string
    {
        return 'Publikování se nepovedlo. Zkuste to prosím znovu.';
    }
}
