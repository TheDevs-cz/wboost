<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\Logo;
use WBoost\Web\Value\SvgImage;

/**
 * @phpstan-type LogoArray null|array{
 *     filePath: string,
 *     detectedColors: array<string>,
 *     colorsMapping?: array<string, array{
 *          background: null|string,
 *          colors: array<string, string>,
 *     }>,
 *     widthInfo?: null|string,
 *     heightInfo?: null|string,
 *  }
 */
final class LogoDoctrineType extends JsonType
{
    public const string NAME = 'logo';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|Logo
    {
        if ($value === null) {
            return null;
        }

        /**
         * @var array{
         *     horizontal: LogoArray,
         *     vertical: LogoArray,
         *     horizontalWithClaim: LogoArray,
         *     verticalWithClaim: LogoArray,
         *     symbol: LogoArray,
         * } $jsonData
         */
        $jsonData = parent::convertToPHPValue($value, $platform);

        return new Logo(
            horizontal: $jsonData['horizontal'] !== null ? SvgImage::fromArray($jsonData['horizontal']) : null,
            vertical: $jsonData['vertical'] !== null ? SvgImage::fromArray($jsonData['vertical']) : null,
            horizontalWithClaim: $jsonData['horizontalWithClaim'] !== null ? SvgImage::fromArray($jsonData['horizontalWithClaim']) : null,
            verticalWithClaim: $jsonData['verticalWithClaim'] !== null ? SvgImage::fromArray($jsonData['verticalWithClaim']) : null,
            symbol: $jsonData['symbol'] !== null ? SvgImage::fromArray($jsonData['symbol']) : null,
        );
    }

    /**
     * @param null|Logo $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [
            'horizontal' => $value->horizontal?->toArray(),
            'vertical' => $value->vertical?->toArray(),
            'horizontalWithClaim' => $value->horizontalWithClaim?->toArray(),
            'verticalWithClaim' => $value->verticalWithClaim?->toArray(),
            'symbol' => $value->symbol?->toArray(),
        ];

        return parent::convertToDatabaseValue($data, $platform);
    }
}
