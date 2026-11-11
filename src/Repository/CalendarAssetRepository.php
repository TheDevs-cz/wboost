<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\CalendarAsset;
use WBoost\Web\Exceptions\CalendarAssetNotFound;
use WBoost\Web\Value\Calendar\CalendarAuthorKind;

readonly final class CalendarAssetRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CalendarAssetNotFound
     */
    public function get(UuidInterface $assetId): CalendarAsset
    {
        $asset = $this->entityManager->find(CalendarAsset::class, $assetId);

        if ($asset instanceof CalendarAsset) {
            return $asset;
        }

        throw new CalendarAssetNotFound();
    }

    public function add(CalendarAsset $asset): void
    {
        $this->entityManager->persist($asset);
    }

    /**
     * @return list<CalendarAsset> upload order
     */
    public function forCalendar(UuidInterface $calendarId): array
    {
        /** @var list<CalendarAsset> $assets */
        $assets = $this->entityManager->createQueryBuilder()
            ->select('asset')
            ->from(CalendarAsset::class, 'asset')
            ->where('asset.calendar = :calendarId')
            ->setParameter('calendarId', $calendarId->toString())
            ->orderBy('asset.createdAt', 'ASC')
            ->addOrderBy('asset.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $assets;
    }

    /**
     * Bytes of everything clients uploaded into this calendar (the main
     * files; previews are not counted).
     */
    public function clientBytes(UuidInterface $calendarId): int
    {
        $bytes = $this->entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM calendar_asset WHERE calendar_id = :calendarId AND uploaded_by_kind = :kind',
            ['calendarId' => $calendarId->toString(), 'kind' => CalendarAuthorKind::Client->value],
        );

        return is_numeric($bytes) ? (int) $bytes : 0;
    }

    /**
     * Which of `$ids` are assets of this calendar.
     *
     * @param list<string> $ids
     * @return array<string, true> keyed by lower-case asset id
     */
    public function existingIds(UuidInterface $calendarId, array $ids): array
    {
        $ids = array_values(array_unique(array_map(strtolower(...), $ids)));

        if ($ids === []) {
            return [];
        }

        $found = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id FROM calendar_asset WHERE calendar_id = :calendarId AND id IN (:ids)',
            ['calendarId' => $calendarId->toString(), 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $existing = [];

        foreach ($found as $id) {
            if (is_string($id)) {
                $existing[strtolower($id)] = true;
            }
        }

        return $existing;
    }
}
