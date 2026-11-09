<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Entity\ManualMockupPage;

/**
 * One page of a manual's sortable "Stránky" list: a mockup page or a CUSTOM
 * content page. Both tables share one position space per manual (see
 * `docs/architecture/manuals.md` "Shared page order"), so the list is the
 * two merged by position. The sort endpoint addresses an item by its typed
 * id `mockup:<uuid>` / `content:<uuid>` because the two tables' ids are
 * independent.
 */
readonly final class ManualPageListItem
{
    public const string MOCKUP = 'mockup';
    public const string CONTENT = 'content';

    private function __construct(
        public null|ManualMockupPage $mockupPage,
        public null|ManualContentPage $contentPage,
    ) {
    }

    public static function mockup(ManualMockupPage $page): self
    {
        return new self($page, null);
    }

    public static function content(ManualContentPage $page): self
    {
        return new self(null, $page);
    }

    public function kind(): string
    {
        return $this->mockupPage !== null ? self::MOCKUP : self::CONTENT;
    }

    public function id(): UuidInterface
    {
        return $this->page()->id;
    }

    public function typedId(): string
    {
        return $this->kind() . ':' . $this->id()->toString();
    }

    public function name(): string
    {
        return $this->page()->name;
    }

    public function position(): int
    {
        return $this->page()->position;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->page()->createdAt;
    }

    /**
     * `mockup:<uuid>` / `content:<uuid>` → [kind, id]; anything else → null.
     *
     * @return null|array{self::MOCKUP|self::CONTENT, UuidInterface}
     */
    public static function parseTypedId(string $typedId): null|array
    {
        $parts = explode(':', $typedId, 2);

        if (count($parts) !== 2 || !Uuid::isValid($parts[1])) {
            return null;
        }

        return match ($parts[0]) {
            self::MOCKUP => [self::MOCKUP, Uuid::fromString($parts[1])],
            self::CONTENT => [self::CONTENT, Uuid::fromString($parts[1])],
            default => null,
        };
    }

    private function page(): ManualMockupPage|ManualContentPage
    {
        $page = $this->mockupPage ?? $this->contentPage;
        assert($page !== null);

        return $page;
    }
}
