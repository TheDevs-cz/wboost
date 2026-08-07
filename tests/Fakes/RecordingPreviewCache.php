<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Fakes;

use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * A stand-in for the `cache.gotenberg_preview` pool that records every call
 * instead of storing anything.
 *
 * It exists so a test can assert the ABSENCE of caching without inspecting a
 * real pool. Enumerating a Redis (or even an in-memory) pool proves only that
 * nothing was *stored*, which stays true whenever the render simply failed —
 * a vacuous assertion. Recording the calls proves the stronger and actually
 * interesting thing: that the renderer never even CONSULTED the pool, i.e. it
 * took its uncached branch.
 *
 * {@see get()} deliberately does NOT invoke the callback. A test that expects a
 * lookup wants to observe the lookup, not pay for the Gotenberg round-trip
 * behind it; a test that expects no lookup never reaches this method at all.
 */
final class RecordingPreviewCache implements TagAwareCacheInterface
{
    /**
     * Every key {@see get()} was asked for, in order.
     *
     * @var list<string>
     */
    public array $lookups = [];

    /**
     * Every tag set handed to {@see invalidateTags()}, in order.
     *
     * @var list<list<string>>
     */
    public array $invalidations = [];

    public function __construct(
        /** What {@see get()} answers with, standing in for a warm cache entry. */
        private readonly string $storedValue = 'RECORDED-CACHE-HIT',
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function get(string $key, callable $callback, null|float $beta = null, null|array &$metadata = null): mixed
    {
        $this->lookups[] = $key;

        // The interface promises the CALLBACK's return type. A double that
        // deliberately never calls the callback cannot honour that generic —
        // answering a canned value without computing one is the whole point.
        return $this->storedValue; // @phpstan-ignore return.type
    }

    public function delete(string $key): bool
    {
        return true;
    }

    /**
     * @param string[] $tags
     */
    public function invalidateTags(array $tags): bool
    {
        $this->invalidations[] = array_values($tags);

        return true;
    }
}
