<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\Color;
use WBoost\Web\Value\ManualColor;
use WBoost\Web\Value\ManualColorType;

final class ManualColorsDoctrineType extends JsonType
{
    public const string NAME = 'manual_colors';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @return null|array<ManualColor>
     *
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|array
    {
        if ($value === null) {
            return null;
        }

        $jsonData = parent::convertToPHPValue($value, $platform);
        assert(is_array($jsonData));

        $colors = [];

        foreach ($jsonData as $color) {
            /** @var array{color: string, type: null|string, pantone?: null|string, cmyk?: array{null|string, null|string, null|string, null|string}} $color */

            $colors[] = new ManualColor(
                color: new Color($color['color']),
                type: $color['type'] === null ? null : ManualColorType::from($color['type']),
                pantone: $color['pantone'] ?? null,
                cmyk: $color['cmyk'] ?? null,
            );
        }

        return $colors;
    }

    /**
     * @param null|array<ManualColor> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $color) {
            if (!is_a($color, ManualColor::class)) {
                throw InvalidType::new($value, self::NAME, [ManualColor::class]);
            }

            $data[] = [
                'color' => (string) $color->color,
                'type' => $color->type?->value,
                'pantone' => $color->pantone,
                'cmyk' => $color->cmyk,
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
