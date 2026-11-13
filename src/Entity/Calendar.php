<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Doctrine\JsonObjectDoctrineType;
use WBoost\Web\Value\Calendar\CalendarType;

/**
 * A desk calendar of a project (module Kalendáře) — one row holding a
 * DESIGN (name, year, type, page, print, features, preset, `layout`) and a
 * CONTENT document made of collections of individually addressable items.
 * Shapes and rules: docs/calendar/architecture.md §B, §C.
 *
 * The server never generates pages and knows no page dimensions: `page`,
 * `print` and `features` stay `{}` until the first design save and the
 * calendar JavaScript fills its defaults. The server stores, validates
 * shapes, enforces roles and merges content item by item.
 */
#[Entity]
#[Table(name: 'calendar')]
#[Index(name: 'idx_calendar_project', columns: ['project_id'])]
#[UniqueConstraint(name: 'uniq_calendar_client_token', columns: ['client_token'])]
class Calendar
{
    /** Route requirement of `{calendarId}` — any UUID-shaped id (not only RFC-versioned ones). */
    public const string ID_REQUIREMENT = '[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}';

    public const int MAX_NAME_LENGTH = 60;
    public const int MAX_CONTENT_BYTES = 2_000_000;

    /** @var array<string, mixed> PageProps, `[]` until the first design save */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $page = [];

    /** @var array<string, mixed> PrintSettings */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $print = [];

    /** @var array<string, bool> Features */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    public array $features = [];

    /** `photoLeft` / `photoRight` / `twoSided`, null until step 2. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, nullable: true)]
    public null|string $layoutPreset = null;

    /** The Layout document as an object tree (see {@see JsonObjectDoctrineType}), null until step 2. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: JsonObjectDoctrineType::NAME, nullable: true)]
    public null|\stdClass $layout = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::SMALLINT, options: ['default' => 1])]
    public int $step = 1;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => false])]
    public bool $finished = false;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => true])]
    public bool $linkElements = true;

    /**
     * Where the layout came from when that is not a preset: `scan` after an
     * AI scan's layout was applied (docs/architecture/ai-scan.md § "Calendar
     * scans"), null otherwise. NOT part of the layout document — step 2 reads
     * it to show the card „Naskenovaný design“.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, nullable: true)]
    public null|string $layoutOrigin = null;

    /** +1 on every successful save. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $revision = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $content Content: `{ v: 1, <collection>: { <id>: item } }`
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Project $project,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 60)]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $year,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: 'string', length: 16, enumType: CalendarType::class, options: ['default' => 'week'])]
        public CalendarType $type,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
        public array $content,

        /** The unguessable client link token (`/k/{token}`), 64 hex characters. */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 64)]
        public string $clientToken,

        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public static function newClientToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Applies an already validated design patch
     * ({@see \WBoost\Web\Services\Calendar\CalendarDesignRules::validate()}):
     * each present key replaces the stored value whole.
     *
     * @param array{name?: string, year?: int, type?: CalendarType, page?: array<string, mixed>, print?: array<string, mixed>, features?: array<string, bool>, preset?: null|string, layout?: null|\stdClass, linkElements?: bool, step?: int, finished?: bool} $patch
     * @return list<string> the design keys whose value really changed
     */
    public function applyDesign(array $patch): array
    {
        $changed = [];

        if (array_key_exists('name', $patch) && $patch['name'] !== $this->name) {
            $this->name = $patch['name'];
            $changed[] = 'name';
        }

        if (array_key_exists('year', $patch) && $patch['year'] !== $this->year) {
            $this->year = $patch['year'];
            $changed[] = 'year';
        }

        if (array_key_exists('type', $patch) && $patch['type'] !== $this->type) {
            $this->type = $patch['type'];
            $changed[] = 'type';
        }

        if (array_key_exists('page', $patch) && !self::same($patch['page'], $this->page)) {
            $this->page = $patch['page'];
            $changed[] = 'page';
        }

        if (array_key_exists('print', $patch) && !self::same($patch['print'], $this->print)) {
            $this->print = $patch['print'];
            $changed[] = 'print';
        }

        if (array_key_exists('features', $patch) && !self::same($patch['features'], $this->features)) {
            $this->features = $patch['features'];
            $changed[] = 'features';
        }

        if (array_key_exists('preset', $patch) && $patch['preset'] !== $this->layoutPreset) {
            $this->layoutPreset = $patch['preset'];
            $changed[] = 'preset';
        }

        if (array_key_exists('layout', $patch) && !self::same($patch['layout'], $this->layout)) {
            $this->layout = $patch['layout'];
            $changed[] = 'layout';
        }

        if (array_key_exists('linkElements', $patch) && $patch['linkElements'] !== $this->linkElements) {
            $this->linkElements = $patch['linkElements'];
            $changed[] = 'linkElements';
        }

        if (array_key_exists('step', $patch) && $patch['step'] !== $this->step) {
            $this->step = $patch['step'];
            $changed[] = 'step';
        }

        if (array_key_exists('finished', $patch) && $patch['finished'] !== $this->finished) {
            $this->finished = $patch['finished'];
            $changed[] = 'finished';
        }

        return $changed;
    }

    /**
     * @param array<string, mixed> $content
     */
    public function replaceContent(array $content): void
    {
        $this->content = $content;
    }

    /**
     * Dokončit / Uložit vzhled: the calendar is set up and reopens in step 5.
     *
     * @return bool true when this was the first finish
     */
    public function finish(): bool
    {
        $first = !$this->finished;
        $this->finished = true;
        $this->step = 5;

        return $first;
    }

    /** @return bool true when the marker changed */
    public function markLayoutOrigin(null|string $origin): bool
    {
        if ($origin === $this->layoutOrigin) {
            return false;
        }

        $this->layoutOrigin = $origin;

        return true;
    }

    public function regenerateClientToken(): void
    {
        $this->clientToken = self::newClientToken();
    }

    /** Every successful save: next revision, fresh `updatedAt`. */
    public function touch(DateTimeImmutable $now): void
    {
        $this->revision++;
        $this->updatedAt = $now;
    }

    /**
     * Value equality of two JSON-shaped values regardless of key order
     * (jsonb reorders keys) and of `5` vs `5.0`.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    private static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sorted(json_decode((string) json_encode($value), true)));
    }

    private static function sorted(mixed $value): mixed
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
