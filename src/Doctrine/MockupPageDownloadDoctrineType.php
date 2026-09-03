<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use WBoost\Web\Value\MockupPageDownload;

/**
 * The single file attached to a whole mockup page (nullable column).
 */
final class MockupPageDownloadDoctrineType extends JsonType
{
    public const string NAME = 'mockup_page_download';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @throws ConversionException
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|MockupPageDownload
    {
        if ($value === null) {
            return null;
        }

        $jsonData = parent::convertToPHPValue($value, $platform);

        if (!is_array($jsonData)) {
            return null;
        }

        /** @var array<string, mixed> $jsonData */

        return MockupPageDownload::fromArray($jsonData);
    }

    /**
     * @param null|MockupPageDownload $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return parent::convertToDatabaseValue($value->toArray(), $platform);
    }
}
