<?php

declare(strict_types=1);

namespace WBoost\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\SocialPost;

readonly final class SocialPostRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SocialPost $post): void
    {
        $this->entityManager->persist($post);
    }

    /**
     * Newest first; `$limit + 1` rows tell the caller whether more exist.
     *
     * @return list<SocialPost>
     */
    public function forProject(Project $project, int $limit): array
    {
        /** @var list<SocialPost> $posts */
        $posts = $this->entityManager->createQueryBuilder()
            ->select('post', 'user')
            ->from(SocialPost::class, 'post')
            ->leftJoin('post.publishedBy', 'user')
            ->where('post.project = :project')
            ->setParameter('project', $project->id)
            ->orderBy('post.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $posts;
    }

    /**
     * Posts of one variant keyed by the fill hash they were published with —
     * the export history marks the matching versions.
     *
     * @return array<string, list<SocialPost>>
     */
    public function byFillHashForVariant(UuidInterface $variantId): array
    {
        /** @var list<SocialPost> $posts */
        $posts = $this->entityManager->createQueryBuilder()
            ->select('post')
            ->from(SocialPost::class, 'post')
            ->where('IDENTITY(post.templateVariant) = :variant')
            ->andWhere('post.fillValuesHash IS NOT NULL')
            ->setParameter('variant', $variantId->toString())
            ->orderBy('post.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $byHash = [];
        foreach ($posts as $post) {
            $byHash[(string) $post->fillValuesHash][] = $post;
        }

        return $byHash;
    }
}
