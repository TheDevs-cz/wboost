<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;

/**
 * A JSONB document hydrated as an OBJECT tree (`stdClass`), not as arrays.
 *
 * Used for `calendar.layout`, a free-form document owned by the calendar
 * JavaScript: it holds maps that are legitimately empty or keyed by digits
 * (`styles.offsets`, `styles.cal.iconCells["4"]["0"]`). Decoded to PHP arrays
 * those would come back out as JSON lists (`{}` → `[]`, `{"0":…}` → `[…]`) and
 * the document would not survive a save → load round trip. An object tree
 * keeps the map / list distinction exactly as the client sent it.
 */
final class JsonObjectDoctrineType extends JsonType
{
    public const string NAME = 'json_object';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|\stdClass
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        if (!is_string($value)) {
            throw new ConversionException('A JSON object column must hold JSON text.');
        }

        try {
            $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConversionException('Invalid JSON object: ' . $exception->getMessage(), 0, $exception);
        }

        return $decoded instanceof \stdClass ? $decoded : null;
    }

    /**
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): null|string
    {
        if (!$value instanceof \stdClass) {
            return null;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new ConversionException('Could not encode the JSON object: ' . $exception->getMessage(), 0, $exception);
        }
    }
}
