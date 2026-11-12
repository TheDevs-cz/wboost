<?php

declare(strict_types=1);

namespace WBoost\Web\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\AiScan\AiScanStandIn;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * Puts a variant into the state a template made from an AI scan is in while
 * it still owes pictures (owner decision O11): canvas objects whose
 * `assetPath` is the project's stand-in file. DAMA keeps every flush inside
 * the test's transaction.
 *
 * A trait of the kernel test cases (it reads the test container).
 */
trait UnfilledPicturesTestData
{

    /**
     * Adds `$count` decorative images ("Součást šablony") still showing the
     * project's stand-in — places no export can fill.
     */
    protected static function addDecorative(string $variantId, int $count = 1): void
    {
        self::rewriteCanvas($variantId, static function (array $objects, string $standInPath) use ($count): array {
            for ($i = 0; $i < $count; $i++) {
                $objects[] = [
                    'type' => 'Image',
                    'left' => 10 + $i * 30, 'top' => 10, 'width' => 120, 'height' => 120,
                    'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top',
                    'assetPath' => $standInPath,
                ];
            }

            return $objects;
        });
    }

    /**
     * Turns the image slot `$inputId` into a fillable full-page background
     * still on the stand-in — unfilled only for an export that supplies no
     * picture for it.
     */
    protected static function putBackgroundOnStandIn(string $variantId, string $inputId): void
    {
        self::rewriteCanvas($variantId, static function (array $objects, string $standInPath) use ($inputId): array {
            $found = false;

            foreach ($objects as $index => $object) {
                if (is_array($object) && ($object['inputId'] ?? null) === $inputId) {
                    $objects[$index] = ['isBackground' => true, 'imagePlaceholder' => true, 'assetPath' => $standInPath] + $object;
                    $found = true;
                }
            }

            if (!$found) {
                throw new \LogicException(sprintf('The variant has no canvas object bound to "%s".', $inputId));
            }

            return $objects;
        });
    }

    /** The project's stand-in row exists, but no canvas references it. */
    protected static function standInPath(string $variantId): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $entityManager->find(TemplateVariant::class, Uuid::fromString($variantId));
        assert($variant instanceof TemplateVariant);

        $path = self::getContainer()->get(AiScanStandIn::class)->projectFile($variant->template->project)->path;
        $entityManager->flush();

        return $path;
    }

    /** The bytes behind the gallery fixture {@see TestDataFixture::FILE_IN_ALLOWED_ID}. */
    protected static function seedAllowedImage(): void
    {
        self::getContainer()->get('oneup_flysystem.minio_filesystem')->write('fixtures/in-allowed.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=', true));
    }

    /**
     * @param callable(array<array-key, mixed>, string): array<array-key, mixed> $change
     */
    private static function rewriteCanvas(string $variantId, callable $change): void
    {
        $standInPath = self::standInPath($variantId);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $entityManager->find(TemplateVariant::class, Uuid::fromString($variantId));
        assert($variant instanceof TemplateVariant);

        $canvas = json_decode($variant->canvas, true);
        $canvas = is_array($canvas) ? $canvas : [];
        $objects = is_array($canvas['objects'] ?? null) ? $canvas['objects'] : [];
        $canvas['objects'] = array_values($change($objects, $standInPath));

        $variant->canvas = json_encode($canvas, JSON_THROW_ON_ERROR);
        $entityManager->flush();
        $entityManager->clear();
    }
}
