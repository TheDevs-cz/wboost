<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\FontFace;

final class FontFacesDoctrineType extends JsonType
{
    public const string NAME = 'font_face[]';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @return null|array<FontFace>
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

        $fonts = [];

        foreach ($jsonData as $fontData) {
            /** @var array{name: string, filePath: string, weight: int, style: string} $fontData */

            $fonts[] = new FontFace(
                name: $fontData['name'],
                weight: $fontData['weight'],
                style: $fontData['style'],
                filePath: $fontData['filePath'],
            );
        }

        return $fonts;
    }

    /**
     * @param null|array<FontFace> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $font) {
            $data[] = [
                'name' => $font->name,
                'weight' => $font->weight,
                'style' => $font->style,
                'filePath' => $font->filePath,
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
