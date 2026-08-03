<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Value\StorageCategory;

final class StorageCategoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, StorageCategory}>
     */
    public static function paths(): iterable
    {
        yield 'gallery upload' => ['file-upload/0191-a/0192-b.png', StorageCategory::GalleryImage];
        yield 'manual logo' => ['manuals/0191-a/logo-symbol-1724056158.svg', StorageCategory::Manual];
        yield 'manual mockup page' => ['manuals/0191-a/pages/0192-b/image-1-1724056158.png', StorageCategory::Manual];
        yield 'social variant background' => ['social-networks/0191-a/background-1725995356.png', StorageCategory::SocialNetwork];
        yield 'social template image' => ['social-networks/templates/0191-a/image-1727462023.png', StorageCategory::SocialNetwork];
        yield 'custom variant background' => ['custom-templates/0191-a/background-1.png', StorageCategory::CustomTemplate];
        yield 'font face' => ['fonts/0191-a/Biotic Bold-1724058155.otf', StorageCategory::Font];
        yield 'email signature background' => ['emails/0191-a/background-1.png', StorageCategory::EmailSignature];
        yield 'publish temp file' => ['social-publish/0191-a.jpg', StorageCategory::SocialPublish];
        yield 'imagine thumbnail' => ['thumbnails/whatever.png', StorageCategory::Thumbnail];
        yield 'unknown' => ['stray-file.txt', StorageCategory::Other];

        // Previews win over the template namespace they are nested in — they
        // are regenerated output, not designer-uploaded input.
        yield 'social preview' => ['social-networks/preview/0191-a.png', StorageCategory::Preview];
        yield 'custom preview' => ['custom-templates/preview/0191-a.png', StorageCategory::Preview];
    }

    #[DataProvider('paths')]
    public function testCategoryIsDerivedFromPathPrefix(string $path, StorageCategory $expected): void
    {
        self::assertSame($expected, StorageCategory::fromPath($path));
    }

    public function testOnlyByProductsAreTransient(): void
    {
        // Transient = unreferenced by design, so never reported as an orphan.
        self::assertTrue(StorageCategory::SocialPublish->isTransient());
        self::assertTrue(StorageCategory::Thumbnail->isTransient());

        self::assertFalse(StorageCategory::GalleryImage->isTransient());
        self::assertFalse(StorageCategory::Preview->isTransient());
        self::assertFalse(StorageCategory::Other->isTransient());
    }
}
