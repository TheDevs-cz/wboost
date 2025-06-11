<?php

declare(strict_types=1);

namespace WBoost\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Value\ProjectSharing;
use WBoost\Web\Value\SharingLevel;

final class ProjectSharingDoctrineType extends JsonType
{
    public const string NAME = 'project_sharing';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @return null|array<ProjectSharing>
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

        $sharing = [];

        foreach ($jsonData as $sharingData) {
            /** @var array{userId: string, level: string} $sharingData */

            $sharing[] = new ProjectSharing(
                userId: Uuid::fromString($sharingData['userId']),
                level: SharingLevel::from($sharingData['level']),
            );
        }

        return $sharing;
    }

    /**
     * @param null|array<ProjectSharing> $value
     * @throws ConversionException
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = [];

        foreach ($value as $sharing) {
            $data[] = [
                'userId' => $sharing->userId->toString(),
                'level' => $sharing->level->value,
            ];
        }

        return parent::convertToDatabaseValue($data, $platform);
    }
}
