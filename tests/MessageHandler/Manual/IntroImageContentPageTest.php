<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Manual;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Entity\ManualContentPage;
use WBoost\Web\Message\Manual\AddManual;
use WBoost\Web\Message\Manual\EditManual;
use WBoost\Web\Repository\ManualContentPageRepository;
use WBoost\Web\Services\Manual\IntroContentPageImage;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\ManualContent\ContentImageFit;
use WBoost\Web\Value\ManualContent\ContentSegment;
use WBoost\Web\Value\ManualContent\HeadingElement;
use WBoost\Web\Value\ManualContent\ImageElement;
use WBoost\Web\Value\ManualContent\ManualContentPageRole;
use WBoost\Web\Value\ManualContent\RichTextElement;
use WBoost\Web\Value\ManualPage;
use WBoost\Web\Value\ManualType;
use WBoost\Web\Value\MockupPageLayout;

/**
 * The legacy "Úvodní obrázek" upload of the add / edit manual form keeps
 * working until P4: it lands in the intro content page, which is what the
 * manual renders.
 */
final class IntroImageContentPageTest extends KernelTestCase
{
    /** 1×1 transparent PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testAddingAManualWithAnIntroImageCreatesItsIntroPage(): void
    {
        $manualId = Uuid::uuid7();

        $this->bus()->dispatch(new AddManual($manualId, Uuid::fromString(TestDataFixture::PROJECT_1_ID), ManualType::Brand, 'Nový manuál', $this->upload()));

        $page = $this->intro($manualId->toString());
        self::assertNotNull($page);
        self::assertSame('Úvod', $page->name);
        self::assertSame(MockupPageLayout::Layout7, $page->layout);

        $manual = self::getContainer()->get(EntityManagerInterface::class)->find(Manual::class, $manualId);
        self::assertInstanceOf(Manual::class, $manual);
        self::assertNotNull($manual->introImage);
        self::assertSame([$manual->introImage], $page->imagePaths());
        self::assertTrue($page->hasContent());
    }

    public function testAddingAManualWithoutIntroImageCreatesNoPage(): void
    {
        $manualId = Uuid::uuid7();

        $this->bus()->dispatch(new AddManual($manualId, Uuid::fromString(TestDataFixture::PROJECT_1_ID), ManualType::Logo, 'Bez úvodu', null));

        self::assertNull($this->intro($manualId->toString()));
    }

    public function testEditingInAnIntroImageBuildsThePageFromTheIntroPageTexts(): void
    {
        $manual = $this->manual1();
        $manual->editPageText(ManualPage::Intro, 'Vítejte', 'Popis úvodu');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->bus()->dispatch(new EditManual($manual->id, $manual->type, $manual->name, $this->upload()));

        $page = $this->intro(TestDataFixture::MANUAL_1_ID);
        self::assertNotNull($page);
        self::assertSame('Vítejte', $page->name);
        $elements = $page->segment(0)->elements;
        self::assertCount(2, $elements);
        self::assertInstanceOf(RichTextElement::class, $elements[0]);
        self::assertSame('Popis úvodu', $elements[0]->doc->plainText());
        self::assertInstanceOf(ImageElement::class, $elements[1]);
    }

    public function testANewUploadReplacesThePreviousPictureInPlace(): void
    {
        $previous = 'manuals/' . TestDataFixture::MANUAL_1_ID . '/intro-1.png';
        $page = $this->seedIntro([
            new HeadingElement('h', 1, 'Nadpis'),
            new ImageElement('img', $previous, ContentImageFit::Cover, 'Popis'),
        ]);

        self::getContainer()->get(IntroContentPageImage::class)->apply($page->manual, $previous, 'manuals/' . TestDataFixture::MANUAL_1_ID . '/intro-2.png');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $elements = $this->intro(TestDataFixture::MANUAL_1_ID)?->segment(0)->elements ?? [];
        self::assertCount(2, $elements);
        self::assertInstanceOf(ImageElement::class, $elements[1]);
        self::assertSame('img', $elements[1]->id);
        self::assertSame('manuals/' . TestDataFixture::MANUAL_1_ID . '/intro-2.png', $elements[1]->path);
        self::assertSame(ContentImageFit::Cover, $elements[1]->fit);
        self::assertSame('Popis', $elements[1]->alt);
    }

    public function testAPictureRemovedInTheEditorComesBackAppended(): void
    {
        $page = $this->seedIntro([new HeadingElement('h', 1, 'Nadpis')]);

        self::getContainer()->get(IntroContentPageImage::class)->apply($page->manual, 'manuals/' . TestDataFixture::MANUAL_1_ID . '/intro-1.png', 'manuals/' . TestDataFixture::MANUAL_1_ID . '/intro-2.png');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $elements = $this->intro(TestDataFixture::MANUAL_1_ID)?->segment(0)->elements ?? [];
        self::assertCount(2, $elements);
        self::assertInstanceOf(HeadingElement::class, $elements[0]);
        self::assertInstanceOf(ImageElement::class, $elements[1]);
        self::assertSame(ContentImageFit::Natural, $elements[1]->fit);
    }

    /**
     * @param list<\WBoost\Web\Value\ManualContent\ContentElement> $elements
     */
    private function seedIntro(array $elements): ManualContentPage
    {
        $page = new ManualContentPage(Uuid::uuid7(), $this->manual1(), ManualContentPageRole::Intro, 'Úvod', MockupPageLayout::Layout7, [new ContentSegment(elements: $elements)], 0, new \DateTimeImmutable());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($page);
        $entityManager->flush();

        return $page;
    }

    private function manual1(): Manual
    {
        $manual = self::getContainer()->get(EntityManagerInterface::class)->find(Manual::class, Uuid::fromString(TestDataFixture::MANUAL_1_ID));
        self::assertInstanceOf(Manual::class, $manual);

        return $manual;
    }

    private function intro(string $manualId): null|ManualContentPage
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(ManualContentPageRepository::class)->introFor(Uuid::fromString($manualId));
    }

    private function upload(): UploadedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'intro') . '.png';
        file_put_contents($file, base64_decode(self::PNG));

        return new UploadedFile($file, 'intro.png', 'image/png', null, true);
    }

    private function bus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }
}
