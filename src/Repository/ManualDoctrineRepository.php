<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Exceptions\ManualNotFound;

/**
 * @extends ServiceEntityRepository<Manual>
 */
final class ManualDoctrineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Manual::class);
    }

    /**
     * @throws ManualNotFound
     */
    public function getBySlug(string $manualSlug, string $projectSlug): Manual
    {
        try {
            $row = $this->getEntityManager()->createQueryBuilder()
                ->from(Manual::class, 'manual')
                ->select('manual', 'project')
                ->join('manual.project', 'project')
                ->where('manual.slug = :manualSlug')
                ->setParameter('manualSlug', $manualSlug)
                ->andWhere('project.slug = :projectSlug')
                ->setParameter('projectSlug', $projectSlug)
                ->getQuery()
                ->getSingleResult();

            if (!$row instanceof Manual) {
                throw new ManualNotFound();
            }

            return $row;
        } catch (NoResultException $e) {
            throw new ManualNotFound(previous: $e);
        }
    }
}
