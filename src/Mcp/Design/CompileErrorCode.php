<?php

declare(strict_types=1);

namespace WBoost\Web\Mcp\Design;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Machine-readable classification of a {@see CompileViolation}.
 *
 * Deliberately separate from {@see \WBoost\Web\Mcp\Design\Dsl\DslErrorCode}
 * rather than folded into it. A parse error says the DOCUMENT is malformed and
 * is answerable by reading the grammar; a compile error says the document is
 * well-formed but refers to something THIS PROJECT does not have, and is
 * answerable only by calling `get_context` or `list_gallery`. Those are
 * different fixes, so they are different codes — and keeping the parser's
 * vocabulary closed is what lets it stay context-free.
 *
 * Mirrors the export API's existing structured refusals (`font_not_allowed`,
 * `container_overflow`): a code plus the allowed set, so the agent can correct
 * itself without a second round trip.
 */
#[Exclude]
enum CompileErrorCode: string
{
    /** `font` is not one of the project's face strings (plan §4.2 invariant 10). */
    case FontNotAllowed = 'font_not_allowed';

    /** `asset` names no picture in this project's gallery (or one that is trashed). */
    case AssetNotFound = 'asset_not_found';

    /**
     * A declaration that cannot do what it says — a fillable background with no
     * stand-in picture, the one combination the grammar allows and the render
     * pipeline cannot honour.
     */
    case InertDeclaration = 'inert_declaration';
}
