<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design\Dsl;

/**
 * Machine-readable classification of a {@see DslViolation}.
 *
 * The human message is what the authoring agent reads and self-corrects from;
 * the code is what `preview_design` / `set_design` (S5-T2/T3) can key on when
 * they turn a parse failure into a structured MCP tool error, mirroring the
 * `container_overflow` / `font_not_allowed` pattern already used by the REST
 * export contract.
 *
 * Deliberately coarse — a code an agent cannot act on differently from its
 * neighbour is token cost, not information.
 */
enum DslErrorCode: string
{
    /** A key the grammar does not define (the hallucinated-key case). */
    case UnknownKey = 'unknown_key';

    /** A key the grammar requires is absent (or explicitly null). */
    case MissingKey = 'missing_key';

    /** The key exists but carries the wrong JSON type. */
    case InvalidType = 'invalid_type';

    /** Right type, unusable value: out of range, not an enum member, malformed. */
    case InvalidValue = 'invalid_value';

    /** Two elements claim the same slug id. */
    case DuplicateId = 'duplicate_id';

    /** A reference (container member/child) names an id no element declares. */
    case UnknownReference = 'unknown_reference';

    /** The document is structurally impossible: cycles, two backgrounds, … */
    case InvalidStructure = 'invalid_structure';

    /** The payload is not a JSON object / not JSON at all. */
    case MalformedDocument = 'malformed_document';
}
