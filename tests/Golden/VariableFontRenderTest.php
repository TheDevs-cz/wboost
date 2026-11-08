<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Golden;

use Imagick;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Sensiolabs\GotenbergBundle\GotenbergScreenshotInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Editor\TemplateVariantImageRenderer;
use WBoost\Web\Services\Font\AddVariableFontFaces;
use WBoost\Web\Services\SocialNetwork\AssetInliner;
use WBoost\Web\Services\SocialNetwork\CanvasPlaceholderGeometry;
use WBoost\Web\Services\SocialNetwork\ImagePlacement;
use WBoost\Web\Services\SocialNetwork\TextInputObjectBinder;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ResolvedImageOverrides;
use WBoost\Web\Value\ResolvedInputOverrides;

/**
 * The real Gotenberg render of a text set in "Inter (Inter ExtraBold)" — a
 * face EXPANDED from the variable Inter — is the same picture as the same
 * text set in the foundry's own static Inter ExtraBold (3.19, the variable
 * file's version; Basic-Latin subset, hinting stripped, OFL). A control render
 * in "Inter (Inter Regular)" must differ clearly, so a silent fallback font
 * cannot pass for a match.
 */
#[Group('gotenberg')]
final class VariableFontRenderTest extends KernelTestCase
{
    private const string VARIABLE = __DIR__ . '/../Fixtures/fonts/Inter-VariableSubset.ttf';
    private const string STATIC_EXTRA_BOLD = __DIR__ . '/../Fixtures/fonts/Inter-ExtraBold-StaticSubset.ttf';

    public function testAnExpandedCutRendersLikeTheFoundrysStaticCut(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $projectId = Uuid::fromString(TestDataFixture::PROJECT_1_ID);
        $filesystem = $container->get(Filesystem::class);

        $copy = (string) tempnam(sys_get_temp_dir(), 'varfont-');
        copy(self::VARIABLE, $copy);
        $container->get(AddVariableFontFaces::class)($projectId, new UploadedFile($copy, 'Inter-Variable.ttf', null, null, true));
        @unlink($copy);

        $faces = [];
        foreach ($container->get(GetFonts::class)->byName($projectId, 'Inter')->faces as $face) {
            $faces[$face->name] = $face;
        }

        try {
            $variant = $container->get(TemplateVariantRepository::class)
                ->get(Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));

            $expanded = $this->render($variant, 'Inter (Inter ExtraBold)');
            $regular = $this->render($variant, 'Inter (Inter Regular)');

            // Same face name, the foundry's static bytes behind it.
            $filesystem->write($faces['Inter ExtraBold']->filePath, (string) file_get_contents(self::STATIC_EXTRA_BOLD));
            $static = $this->render($variant, 'Inter (Inter ExtraBold)');

            $sameError = $expanded->compareImages($static, Imagick::METRIC_MEANSQUAREERROR)[1];
            $controlError = $expanded->compareImages($regular, Imagick::METRIC_MEANSQUAREERROR)[1];

            fwrite(\STDERR, sprintf("\nexpanded vs static ExtraBold MSE=%.8f, vs Regular MSE=%.8f\n", $sameError, $controlError));
            $expanded->writeImage(sys_get_temp_dir() . '/varfont-expanded.png');
            $static->writeImage(sys_get_temp_dir() . '/varfont-static.png');

            self::assertGreaterThan(1e-3, $controlError, 'ExtraBold and Regular must look different — else a fallback font rendered both.');
            self::assertLessThan(1e-4, $sameError, 'The expanded ExtraBold must render like the static ExtraBold.');
        } finally {
            foreach ($faces as $face) {
                if ($filesystem->fileExists($face->filePath)) {
                    $filesystem->delete($face->filePath);
                }
            }
        }
    }

    private function render(TemplateVariant $variant, string $fontFamily): Imagick
    {
        $width = $variant->dimension->width();
        $height = $variant->dimension->height();

        $variant->canvas = json_encode([
            'version' => '5.2.4',
            'objects' => [
                ['type' => 'Rect', 'left' => 0, 'top' => 0, 'width' => $width, 'height' => $height, 'fill' => '#ffffff', 'originX' => 'left', 'originY' => 'top'],
                [
                    'type' => 'Textbox', 'left' => 40, 'top' => 60, 'width' => $width - 80.0, 'height' => 300,
                    'fontSize' => 96, 'fontFamily' => $fontFamily, 'fill' => '#111111', 'lineHeight' => 1.16,
                    'originX' => 'left', 'originY' => 'top', 'text' => "Hamburgefonstiv\nQuick Brown Fox 0123",
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $variant->inputs = [];

        $png = $this->renderer()->renderToBytes($variant, new ResolvedInputOverrides([], []), ResolvedImageOverrides::none());

        $image = new Imagick();
        $image->readImageBlob($png);

        return $image;
    }

    /** A fresh renderer per call — its font cache must not carry the old bytes over. */
    private function renderer(): TemplateVariantImageRenderer
    {
        $container = self::getContainer();
        $geometry = new CanvasPlaceholderGeometry();
        $projectDir = (string) $container->getParameter('kernel.project_dir');

        return new TemplateVariantImageRenderer(
            $container->get(GotenbergScreenshotInterface::class),
            $container->get(GetFonts::class),
            $container->get(AssetInliner::class),
            $geometry,
            new TextInputObjectBinder($geometry),
            new ImagePlacement(),
            $container->get(UploaderHelper::class),
            $projectDir . '/assets/fabric/fabric-7.3.1.min.js',
            $projectDir . '/assets/editor/fabric_break_word.js',
            $projectDir . '/assets/editor/container_layout.js',
            $projectDir . '/assets/editor/rich_text_runs.js',
            $projectDir . '/assets/editor/rich_text_blocks.js',
            new TagAwareAdapter(new ArrayAdapter()),
        );
    }
}
