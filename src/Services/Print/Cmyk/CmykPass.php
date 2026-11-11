<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Print\Cmyk;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What one CMYK export carries from chunk to chunk: the colour rules (one
 * table for the whole file) and the pictures already converted.
 *
 * A picture every chunk repeats (a header or background picture) is
 * converted once: its result is kept under the digest of the source stream
 * for as long as consecutive chunks keep asking for it. A page photo is
 * asked for by one chunk only, so its result is dropped with the next
 * {@see endChunk()} — the work directory never holds more than the shared
 * pictures plus the chunk at hand.
 */
#[Exclude]
final class CmykPass
{
    /** @var array<string, array{path: null|string, kind: string, inverted: bool, filter: null|string, used: bool, fresh: bool}> */
    private array $pictures = [];

    private int $serial = 0;

    /** @var array{pictures: int, converted: int, reused: int} */
    private array $counts = ['pictures' => 0, 'converted' => 0, 'reused' => 0];

    /**
     * @param string $directory an existing directory for the work files
     */
    public function __construct(
        public readonly CmykColorRules $rules,
        public readonly string $directory,
    ) {
    }

    public function tempFile(string $suffix): string
    {
        return sprintf('%s/cmyk-%06d.%s', $this->directory, ++$this->serial, $suffix);
    }

    /**
     * @return null|array{path: null|string, kind: string, inverted: bool, filter: null|string, used: bool, fresh: bool}
     */
    public function picture(string $digest): null|array
    {
        if (!isset($this->pictures[$digest])) {
            return null;
        }

        if (!$this->pictures[$digest]['fresh']) {
            ++$this->counts['pictures'];
            ++$this->counts['reused'];
        }

        $this->pictures[$digest]['used'] = true;
        $this->pictures[$digest]['fresh'] = false;

        return $this->pictures[$digest];
    }

    /**
     * @param null|string $path the converted stream data (null: the source stream is kept as it is)
     * @param string $kind `cmyk` or `gray`
     * @param bool $inverted a CMYK JPEG with Adobe's inverted samples
     * @param null|string $filter the filter of the converted data
     */
    public function keepPicture(string $digest, null|string $path, string $kind, bool $inverted, null|string $filter): void
    {
        ++$this->counts['pictures'];
        ++$this->counts['converted'];
        $this->pictures[$digest] = ['path' => $path, 'kind' => $kind, 'inverted' => $inverted, 'filter' => $filter, 'used' => true, 'fresh' => true];
    }

    public function endChunk(): void
    {
        foreach ($this->pictures as $digest => $picture) {
            if (!$picture['used']) {
                if ($picture['path'] !== null) {
                    @unlink($picture['path']);
                }

                unset($this->pictures[$digest]);

                continue;
            }

            $this->pictures[$digest]['used'] = false;
        }
    }

    /** Removes the converted pictures that were kept for a next chunk. */
    public function close(): void
    {
        foreach ($this->pictures as $picture) {
            if ($picture['path'] !== null) {
                @unlink($picture['path']);
            }
        }

        $this->pictures = [];
    }

    /**
     * @return array{pictures: int, converted: int, reused: int}
     */
    public function counts(): array
    {
        return $this->counts;
    }
}
