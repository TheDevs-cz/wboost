<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Font;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\UuidInterface;

/**
 * Rewrites every stored reference to a font family string when the family
 * is renamed. Fonts are addressed by STRING everywhere outside the fonts
 * page — a textbox's `fontFamily` (and per-character `styles`), an input's
 * `allowedFonts`, a rich sample's runs, an export version's fill values —
 * so a rename without this would strand every template on a fallback font.
 *
 * Done as text replacement over the JSONB columns rather than a document
 * walk: the face strings (`"Old (Old Bold)"`) are quoted, parenthesised and
 * unique enough that a collision with user text is not a realistic concern,
 * while a bare family name (`"Old"`) IS ordinary text and is therefore only
 * touched in a `"fontFamily": …` position. Two spellings per needle cover
 * the two places a reference can sit: directly in the document (jsonb's
 * canonical `"key": "value"` text form) and inside a JSON string that itself
 * holds JSON (a rich sample / fill-value envelope, where quotes are escaped).
 *
 * Runs inside the caller's transaction (Messenger wraps the handler), so a
 * failed rewrite rolls the rename back with it.
 */
readonly final class RewriteFontReferences
{
    private const array TARGETS = [
        ['template_variant', 'canvas'],
        ['template_variant', 'inputs'],
        ['template_export_version', 'fill_values'],
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param list<string> $faceNames the font's face names (unchanged by the rename)
     * @return int rows touched across all columns
     */
    public function rename(UuidInterface $projectId, string $oldName, string $newName, array $faceNames): int
    {
        if ($oldName === $newName) {
            return 0;
        }

        /** @var list<array{string, string}> $pairs needle → replacement, both already quoted the way they appear */
        $pairs = [];

        foreach ($faceNames as $faceName) {
            $old = sprintf('%s (%s)', $oldName, $faceName);
            $new = sprintf('%s (%s)', $newName, $faceName);
            $pairs[] = [self::quoted($old), self::quoted($new)];
            $pairs[] = [self::escapedQuoted($old), self::escapedQuoted($new)];
        }

        // The bare family name only where it is a font reference.
        $pairs[] = ['"fontFamily": ' . self::quoted($oldName), '"fontFamily": ' . self::quoted($newName)];
        $pairs[] = ['\"fontFamily\":' . self::escapedQuoted($oldName), '\"fontFamily\":' . self::escapedQuoted($newName)];

        $touched = 0;

        foreach (self::TARGETS as [$table, $column]) {
            foreach ($pairs as [$needle, $replacement]) {
                $touched += $this->replaceIn($table, $column, $projectId, $needle, $replacement);
            }
        }

        return $touched;
    }

    private function replaceIn(string $table, string $column, UuidInterface $projectId, string $needle, string $replacement): int
    {
        $scope = $table === 'template_variant'
            ? 'template_id IN (SELECT id FROM template WHERE project_id = :projectId)'
            : 'template_id IN (SELECT id FROM template WHERE project_id = :projectId)';

        $sql = sprintf(
            'UPDATE %1$s SET %2$s = replace(%2$s::text, :needle, :replacement)::jsonb WHERE %3$s AND %2$s::text LIKE :pattern',
            $table,
            $column,
            $scope,
        );

        return (int) $this->connection->executeStatement($sql, [
            'projectId' => $projectId->toString(),
            'needle' => $needle,
            'replacement' => $replacement,
            'pattern' => '%' . self::likeEscape($needle) . '%',
        ]);
    }

    /** The string as jsonb's text form prints it: JSON-encoded, slashes and unicode untouched. */
    private static function quoted(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** The same string nested inside a JSON string value (an envelope): its quotes escaped once more. */
    private static function escapedQuoted(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\"'], self::quoted($value));
    }

    private static function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
