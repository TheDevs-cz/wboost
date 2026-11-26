<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\ProjectSocialAccount;
use WBoost\Web\Value\SocialPlatform;

readonly final class ProjectSocialAccountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ProjectSocialAccount $account): void
    {
        $this->entityManager->persist($account);
    }

    public function remove(ProjectSocialAccount $account): void
    {
        $this->entityManager->remove($account);
    }

    public function get(UuidInterface $id): null|ProjectSocialAccount
    {
        return $this->entityManager->find(ProjectSocialAccount::class, $id);
    }

    public function findForProject(Project|UuidInterface $project, SocialPlatform $platform): null|ProjectSocialAccount
    {
        return $this->entityManager->getRepository(ProjectSocialAccount::class)->findOneBy([
            'project' => $project,
            'platform' => $platform,
        ]);
    }

    /**
     * Every project connected to this account — by either id Meta may send
     * in its deauthorize / data-deletion callbacks.
     *
     * @return list<ProjectSocialAccount>
     */
    public function findByExternalId(SocialPlatform $platform, string $externalId): array
    {
        /** @var list<ProjectSocialAccount> $accounts */
        $accounts = $this->entityManager->createQueryBuilder()
            ->from(ProjectSocialAccount::class, 'a')
            ->select('a')
            ->where('a.platform = :platform')
            ->andWhere('a.accountId = :id OR a.accountUserId = :id')
            ->setParameter('platform', $platform)
            ->setParameter('id', $externalId)
            ->getQuery()
            ->getResult();

        return $accounts;
    }

    /**
     * @return list<UuidInterface>
     */
    public function connectedIds(SocialPlatform $platform): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->from(ProjectSocialAccount::class, 'a')
            ->select('a.id')
            ->where('a.platform = :platform')
            ->andWhere('a.needsReconnect = false')
            ->setParameter('platform', $platform)
            ->getQuery()
            ->getArrayResult();

        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && ($row['id'] ?? null) instanceof UuidInterface) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }
}
