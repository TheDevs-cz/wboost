<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

use function get_debug_type;
use function is_resource;
use function is_string;
use function sprintf;
use function stream_get_contents;

/**
 * "JSON as string" custom type backed by a PostgreSQL JSONB column.
 *
 * Unlike the built-in JsonType (which decodes/encodes between PHP arrays and
 * JSON strings), this type keeps the value as the raw JSON string on both
 * sides. The Fabric.js canvas is serialized/deserialized as a string by the
 * client; we just need PostgreSQL to validate JSON shape on insert and unlock
 * future server-side `jsonb_set`-style manipulation.
 *
 * Empty / unset canvases are normalized to the literal "{}" — JSONB does not
 * accept the empty string as a valid document.
 */
final class CanvasJsonbType extends Type
{
    public const string NAME = 'canvas_jsonb';

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string, mixed> $column
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        // Some drivers expose JSONB as a stream resource.
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? null : $contents;
        }

        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                sprintf('Expected string from JSONB column, got %s.', get_debug_type($value)),
            );
        }

        return $value;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \UnexpectedValueException(
                sprintf('CanvasJsonbType expects a JSON string, got %s.', get_debug_type($value)),
            );
        }

        return $value;
    }
}
