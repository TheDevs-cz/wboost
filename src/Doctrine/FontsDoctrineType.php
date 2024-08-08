<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\Font;

final class FontsDoctrineType extends JsonType
{
    public const string NAME = 'fonts[]';

    /**
     * @return null|array<Font>
     *
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if ($value === null) {
            return null;
        }

        $jsonData = parent::convertToPHPValue($value, $platform);
        assert(is_array($jsonData));

        $fonts = [];

        foreach ($jsonData as $fontData) {
            /** @var array{file: string, weight: int, style: string} $fontData */

            $fonts[] = new Font(
                file: $fontData['file'],
                weight: $fontData['weight'],
                style: $fontData['style'],
            );
        }

        return $fonts;
    }

    /**
     * @param null|array<Font> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $font) {
            if (!is_a($font, Font::class)) {
                throw InvalidType::new($value, self::NAME, [Font::class]);
            }

            $data[] = [
                'file' => $font->file,
                'weight' => $font->weight,
                'style' => $font->style,
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
