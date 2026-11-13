<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use AsyncAws\S3\S3Client;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Scan files, in the PRIVATE bucket (no anonymous policy, its own Flysystem
 * filesystem). Nothing here ever produces a URL: bytes leave only through a
 * controller behind the use permission, or through the scanner API.
 *
 * Keys: `ai-scan/{projectId}/{scanId}/{secret}/{file}`, where `secret` is the
 * scan's 128 random bits — in no URL, e-mail or log. Files: `original.{ext}`,
 * `working.webp`, `preview.webp`, `candidate-{n}.webp`, `held/{n}.{ext}`.
 *
 * The app never decodes these bytes: it stores and hands them on.
 */
final readonly class AiScanStorage
{
    public const string ROOT = 'ai-scan';
    public const string WORKING = 'working.webp';
    public const string PREVIEW = 'preview.webp';
    /** The proposal as it renders with the review's fixes — written by every fix (P3). */
    public const string PROPOSAL = 'proposal.webp';

    public function __construct(
        #[Autowire(service: 'oneup_flysystem.private_filesystem')]
        private FilesystemOperator $filesystem,
        private S3Client $s3,
        #[Autowire('%env(S3_PRIVATE_BUCKET_NAME)%')]
        private string $bucket,
    ) {
    }

    public static function directory(string $projectId, string $scanId, string $secret): string
    {
        return sprintf('%s/%s/%s/%s', self::ROOT, $projectId, $scanId, $secret);
    }

    public static function originalName(string $extension): string
    {
        return 'original.' . $extension;
    }

    public static function candidateName(int $number): string
    {
        return sprintf('candidate-%d.webp', $number);
    }

    /**
     * @throws FilesystemException
     */
    public function write(string $directory, string $file, string $bytes): void
    {
        $this->filesystem->write($directory . '/' . $file, $bytes);
    }

    /**
     * @param resource $stream
     * @throws FilesystemException
     */
    public function writeStream(string $directory, string $file, $stream): void
    {
        $this->filesystem->writeStream($directory . '/' . $file, $stream);
    }

    public function read(string $directory, string $file): null|string
    {
        try {
            return $this->filesystem->read($directory . '/' . $file);
        } catch (FilesystemException) {
            return null;
        }
    }

    public function exists(string $directory, string $file): bool
    {
        try {
            return $this->filesystem->fileExists($directory . '/' . $file);
        } catch (FilesystemException) {
            return false;
        }
    }

    /**
     * Removes the candidate renders of a scan directory, except `$keep` — the
     * kept one, which is the proposal's picture. Candidates exist for the
     * run that rendered them: once it has ended, or another run starts, the
     * others are nothing anybody reads. Returns how many were removed; a
     * storage failure is left to the nightly purge.
     */
    public function dropCandidates(string $directory, null|int $keep = null): int
    {
        $dropped = 0;

        try {
            /** @var StorageAttributes $entry */
            foreach ($this->filesystem->listContents($directory, false) as $entry) {
                if (!$entry->isFile() || preg_match('/(?:^|\/)candidate-(\d+)\.webp$/', $entry->path(), $match) !== 1) {
                    continue;
                }

                if ($keep !== null && (int) $match[1] === $keep) {
                    continue;
                }

                $this->filesystem->delete($entry->path());
                $dropped++;
            }
        } catch (FilesystemException) {
            // Left to the purge.
        }

        return $dropped;
    }

    /** Everything of one scan. Failures are the purge's to retry. */
    public function deleteScan(string $projectId, string $scanId): bool
    {
        return $this->deleteDirectory(sprintf('%s/%s/%s', self::ROOT, $projectId, $scanId));
    }

    /** Everything of one project — called when the project is deleted. */
    public function deleteProject(string $projectId): bool
    {
        return $this->deleteDirectory(sprintf('%s/%s', self::ROOT, $projectId));
    }

    /**
     * Every scan directory in the bucket with the time of its NEWEST object:
     * `{projectId, scanId, lastModified}` — what the purge's 24-hour rule
     * walks.
     *
     * @return list<array{projectId: string, scanId: string, lastModified: int}>
     */
    public function scanDirectories(): array
    {
        /** @var array<string, array{projectId: string, scanId: string, lastModified: int}> $found */
        $found = [];

        try {
            /** @var StorageAttributes $entry */
            foreach ($this->filesystem->listContents(self::ROOT, true) as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }

                $parts = explode('/', $entry->path());

                if (count($parts) < 4) {
                    continue;
                }

                $key = $parts[1] . '/' . $parts[2];
                $modified = $entry->lastModified() ?? 0;

                if (!isset($found[$key]) || $modified > $found[$key]['lastModified']) {
                    $found[$key] = ['projectId' => $parts[1], 'scanId' => $parts[2], 'lastModified' => $modified];
                }
            }
        } catch (FilesystemException) {
            return [];
        }

        return array_values($found);
    }

    /** Whether the private bucket answers (the status command's check). */
    public function reachable(): bool
    {
        try {
            // A listing, not `fileExists()`: on S3 a HEAD of an object in a
            // bucket that does not exist is a plain 404 — "no such file" —
            // and said "reachable" on a box that had no bucket (P4). A
            // listing of a missing bucket throws.
            foreach ($this->filesystem->listContents(self::ROOT, false) as $ignored) {
                break;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Creates the private bucket when it is missing. Development and tests
     * only (the fake run calls it); in production the bucket is made with the
     * infrastructure, without an anonymous policy.
     */
    public function ensureBucket(): void
    {
        try {
            if ($this->s3->bucketExists(['Bucket' => $this->bucket])->isSuccess()) {
                return;
            }

            $this->s3->createBucket(['Bucket' => $this->bucket]);
        } catch (\Throwable) {
            // No object store here (tests use a local directory): nothing to make.
        }
    }

    private function deleteDirectory(string $directory): bool
    {
        try {
            $this->filesystem->deleteDirectory($directory);

            return true;
        } catch (FilesystemException) {
            return false;
        }
    }
}
