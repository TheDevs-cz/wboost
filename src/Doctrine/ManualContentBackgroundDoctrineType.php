<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Exceptions\InvalidManualContent;
use WBoost\Web\Value\ManualContent\ContentPageBackground;

/**
 * `manual_content_page.background`: the page's background picture (null =
 * none), decoded through the strict value object like the segments.
 */
final class ManualContentBackgroundDoctrineType extends JsonType
{
    public const string NAME = 'manual_content_background';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|ContentPageBackground
    {
        if ($value === null) {
            return null;
        }

        try {
            return ContentPageBackground::fromArray(parent::convertToPHPValue($value, $platform));
        } catch (InvalidManualContent $exception) {
            throw new ConversionException('Invalid manual content background: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param null|ContentPageBackground $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (!$value instanceof ContentPageBackground) {
            return null;
        }

        return parent::convertToDatabaseValue($value->toArray(), $platform);
    }
}
