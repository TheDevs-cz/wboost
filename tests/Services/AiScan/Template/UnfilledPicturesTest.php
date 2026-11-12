<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan\Template;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Services\AiScan\Template\UnfilledExportGuard;
use WBoost\Web\Services\AiScan\Template\UnfilledPictures;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\UnfilledPicturesTestData;

/**
 * @covers \WBoost\Web\Services\AiScan\Template\UnfilledPictures
 * @covers \WBoost\Web\Services\AiScan\Template\UnfilledExportGuard
 */
final class UnfilledPicturesTest extends KernelTestCase
{
    use UnfilledPicturesTestData;

    private const string VARIANT = TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID;
    private const string SLOT = TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID;

    public function testAnOrdinaryTemplateOwesNothingEvenOnceTheProjectHasAStandIn(): void
    {
        self::bootKernel();
        // The fixture slot shows a designer's sample (`assetPath` of another
        // file) — only the project's stand-in ROW makes a picture unfilled.
        self::standInPath(self::VARIANT);

        self::assertSame(['fixed' => 0, 'fillable' => []], $this->pictures()->inVariant($this->variant()));
        self::assertSame(0, $this->pictures()->count($this->variant()));
        self::assertSame(0, $this->pictures()->forExport($this->variant(), []));
    }

    public function testDecorativePicturesAreOwedWhateverTheExportSupplies(): void
    {
        self::bootKernel();
        self::addDecorative(self::VARIANT, 2);

        self::assertSame(['fixed' => 2, 'fillable' => []], $this->pictures()->inVariant($this->variant()));
        self::assertSame(2, $this->pictures()->count($this->variant()));
        self::assertSame(2, $this->pictures()->forExport($this->variant(), [self::SLOT => TestDataFixture::FILE_IN_ALLOWED_ID]));
    }

    public function testAFillableBackgroundIsOwedOnlyByAnExportThatSuppliesNoPicture(): void
    {
        self::bootKernel();
        self::putBackgroundOnStandIn(self::VARIANT, self::SLOT);
        self::addDecorative(self::VARIANT);
        $variant = $this->variant();

        self::assertSame(['fixed' => 1, 'fillable' => [self::SLOT]], $this->pictures()->inVariant($variant));
        self::assertSame(2, $this->pictures()->count($variant));

        self::assertSame(2, $this->pictures()->forExport($variant, []));
        self::assertSame(2, $this->pictures()->forExport($variant, [self::SLOT => '']));
        self::assertSame(2, $this->pictures()->forExport($variant, [self::SLOT => ['scale' => 1.2]]));
        self::assertSame(2, $this->pictures()->forExport($variant, ['another-slot' => TestDataFixture::FILE_IN_ALLOWED_ID]));

        self::assertSame(1, $this->pictures()->forExport($variant, [self::SLOT => TestDataFixture::FILE_IN_ALLOWED_ID]));
        self::assertSame(1, $this->pictures()->forExport($variant, [self::SLOT => ['imageId' => TestDataFixture::FILE_IN_ALLOWED_ID]]));
        self::assertSame(1, $this->pictures()->forExport($variant, [self::SLOT => ['hide' => true]]));
        self::assertSame(1, $this->pictures()->forExport($variant, [self::SLOT => ['hide' => '1']]), 'the web form posts strings');
    }

    public function testTheStandInOfAnotherProjectIsJustAPicture(): void
    {
        self::bootKernel();
        // Project 2's stand-in path on a project 1 canvas.
        $foreign = self::standInPath(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID);
        $variant = $this->variant();

        if ($variant->template->project->id->toString() === $this->variant(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_2_ID)->template->project->id->toString()) {
            self::markTestSkipped('The two fixture variants share a project.');
        }

        $canvas = json_decode($variant->canvas, true);
        self::assertIsArray($canvas);
        self::assertIsArray($canvas['objects']);
        $canvas['objects'][] = ['type' => 'Image', 'assetPath' => $foreign];
        $variant->canvas = json_encode($canvas, JSON_THROW_ON_ERROR);

        self::assertSame(0, $this->pictures()->count($variant));
    }

    public function testTheCzechReasonFollowsThePluralForms(): void
    {
        self::assertSame(
            'Šablona má 1 nedoplněný obrázek. V exportu bude na jeho místě šedý zástupný obrázek. Doplňte ho v editoru, nebo exportujte přesto.',
            UnfilledExportGuard::czechReason(1),
        );
        self::assertSame(
            'Šablona má 3 nedoplněné obrázky. V exportu budou na jejich místě šedé zástupné obrázky. Doplňte je v editoru, nebo exportujte přesto.',
            UnfilledExportGuard::czechReason(3),
        );
        self::assertSame(
            'Šablona má 5 nedoplněných obrázků. V exportu bude na jejich místě 5 šedých zástupných obrázků. Doplňte je v editoru, nebo exportujte přesto.',
            UnfilledExportGuard::czechReason(5),
        );
    }

    private function pictures(): UnfilledPictures
    {
        // Built by hand: the service is private and inlined into its one user.
        return new UnfilledPictures(self::getContainer()->get(Connection::class));
    }

    private function variant(string $id = self::VARIANT): TemplateVariant
    {
        $variant = self::getContainer()->get(EntityManagerInterface::class)->find(TemplateVariant::class, Uuid::fromString($id));
        self::assertInstanceOf(TemplateVariant::class, $variant);

        return $variant;
    }
}
