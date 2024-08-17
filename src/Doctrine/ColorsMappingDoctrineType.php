<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\ColorMapping;

final class ColorsMappingDoctrineType extends JsonType
{
    public const string NAME = 'colors_mapping';

    /**
     * @return null|array<ColorMapping>
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

        $mappings = [];

        foreach ($jsonData as $mapping) {
            /** @var array{colorHex: string, targetPrimaryColorNumber: int} $mapping */

            $mappings[] = new ColorMapping(
                colorHex: $mapping['colorHex'],
                targetPrimaryColorNumber: $mapping['targetPrimaryColorNumber'],
            );
        }

        return $mappings;
    }

    /**
     * @param null|array<ColorMapping> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $mapping) {
            if (!is_a($mapping, ColorMapping::class)) {
                throw InvalidType::new($value, self::NAME, [ColorMapping::class]);
            }

            $data[] = [
                'colorHex' => $mapping->colorHex,
                'targetPrimaryColorNumber' => $mapping->targetPrimaryColorNumber,
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
