<?php

declare(strict_types=1);

namespace WBoost\Web\Exceptions;

/**
 * A bleed / crop-marks value the machine-facing writers (REST API + MCP)
 * refuse: out of the web form's range, of the wrong type, or a real print
 * setup on a screen (px / preset) format. The message is consumer-facing
 * English; `reason` is the machine-readable code the API answers
 * (`invalid_bleed`, `invalid_crop_marks`, `print_setup_not_available`, and
 * for a variant `missing_print_setup` / `document_page` — the latter with the
 * `documentId` whose print setup the page follows).
 *
 * Extends \InvalidArgumentException so callers that only know
 * {@see \WBoost\Web\Services\TemplateDocument\DocumentWriteInput}'s generic
 * contract keep turning it into a 400 / tool error.
 */
final class InvalidPrintSetup extends \InvalidArgumentException
{
    public function __construct(
        string $message,
        readonly public string $reason,
        readonly public null|string $documentId = null,
    ) {
        parent::__construct($message);
    }
}
