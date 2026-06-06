<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemReader;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Repository\FileDirectoryRepository;
use WBoost\Web\Repository\FileUploadRepository;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\PlaceholderAllowedDirectories;
use WBoost\Web\Services\SocialNetwork\ResolveImageOverrides;
use WBoost\Web\Value\EditorImageInput;

/**
 * Validation / parsing coverage that needs no real FileUpload. The file-backed
 * happy paths (allowed-folder check, constraint enforcement, inline + natural
 * size) are exercised end-to-end by the API export test with real fixtures.
 *
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveImageOverrides
 */
final class ResolveImageOverridesTest extends TestCase
{
    private const string INPUT_A = '11111111-1111-4111-8111-111111111111';

    public function testUnfilledSlotsAreSkipped(): void
    {
        $result = $this->resolver()->resolve([$this->input(self::INPUT_A)], Uuid::uuid4(), []);

        self::assertSame([], $result->images);
        self::assertSame([], $result->hidden);
    }

    public function testProvidedKeyWithoutMatchingSlotIsIgnored(): void
    {
        $result = $this->resolver()->resolve([], Uuid::uuid4(), [self::INPUT_A => Uuid::uuid4()->toString()]);

        self::assertSame([], $result->images);
        self::assertSame([], $result->hidden);
    }

    public function testHideOnHidableSlotMarksHidden(): void
    {
        $result = $this->resolver()->resolve(
            [$this->input(self::INPUT_A, hidable: true)],
            Uuid::uuid4(),
            [self::INPUT_A => ['hide' => true]],
        );

        self::assertSame([], $result->images);
        self::assertSame([self::INPUT_A => true], $result->hidden);
    }

    public function testHideOnNonHidableSlotIsIgnored(): void
    {
        $result = $this->resolver()->resolve(
            [$this->input(self::INPUT_A, hidable: false)],
            Uuid::uuid4(),
            [self::INPUT_A => ['hide' => true]],
        );

        self::assertSame([], $result->images);
        self::assertSame([], $result->hidden);
    }

    public function testTransformWithoutImageIdIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolver()->resolve(
            [$this->input(self::INPUT_A, allowResize: true)],
            Uuid::uuid4(),
            [self::INPUT_A => ['scale' => 2.0]],
        );
    }

    public function testNonStringImageIdIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolver()->resolve(
            [$this->input(self::INPUT_A)],
            Uuid::uuid4(),
            [self::INPUT_A => ['imageId' => 123]],
        );
    }

    public function testNonNumericScaleIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolver()->resolve(
            [$this->input(self::INPUT_A, allowResize: true)],
            Uuid::uuid4(),
            [self::INPUT_A => ['imageId' => Uuid::uuid4()->toString(), 'scale' => 'big']],
        );
    }

    public function testNonBooleanHideIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolver()->resolve(
            [$this->input(self::INPUT_A, hidable: true)],
            Uuid::uuid4(),
            [self::INPUT_A => ['hide' => 'yes']],
        );
    }

    public function testInvalidImageIdUuidIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        $this->resolver()->resolve(
            [$this->input(self::INPUT_A)],
            Uuid::uuid4(),
            [self::INPUT_A => 'not-a-uuid'],
        );
    }

    public function testUnknownImageIdIsRejected(): void
    {
        // The stub EntityManager::find() returns null → FileUploadNotFound → 400.
        $this->expectException(BadRequestHttpException::class);

        $this->resolver()->resolve(
            [$this->input(self::INPUT_A)],
            Uuid::uuid4(),
            [self::INPUT_A => Uuid::uuid4()->toString()],
        );
    }

    private function resolver(): ResolveImageOverrides
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);

        return new ResolveImageOverrides(
            new FileUploadRepository($entityManager),
            new AssetInliner($this->createStub(FilesystemReader::class)),
            new PlaceholderAllowedDirectories(new FileDirectoryRepository($entityManager)),
        );
    }

    /**
     * @param list<string> $allowedDirs
     */
    private function input(
        string $inputId,
        bool $hidable = false,
        bool $allowMove = false,
        bool $allowResize = false,
        bool $allowRotate = false,
        array $allowedDirs = [],
    ): EditorImageInput {
        return new EditorImageInput($inputId, 'Photo', null, $allowMove, $allowResize, $allowRotate, $hidable, $allowedDirs);
    }
}
