<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\Font;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Font;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\FontNameTaken;
use WBoost\Web\Message\Font\RenameFont;
use WBoost\Web\Query\GetFonts;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\FontFace;

/**
 * A font is addressed by its family STRING everywhere; renaming the family
 * must carry every reference along or the templates fall back to a
 * substitute font.
 *
 * @covers \WBoost\Web\MessageHandler\Font\RenameFontHandler
 * @covers \WBoost\Web\Services\Font\RewriteFontReferences
 */
final class RenameFontHandlerTest extends KernelTestCase
{
    public function testRenameRewritesCanvasAllowlistsAndKeepsFaceNames(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $variant = $entityManager->find(TemplateVariant::class, Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));
        self::assertInstanceOf(TemplateVariant::class, $variant);
        self::assertStringContainsString('"Rubik (Rubik Bold)"', $variant->canvas);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new RenameFont(Uuid::fromString(TestDataFixture::FONT_RUBIK_ID), ' Rubik Neu '));
        $entityManager->flush();
        $entityManager->clear();

        $font = self::getContainer()->get(GetFonts::class)->byName(Uuid::fromString(TestDataFixture::PROJECT_1_ID), 'Rubik Neu');
        self::assertSame(['Rubik Regular', 'Rubik Bold'], array_map(static fn ($face): string => $face->name, $font->faces));
        self::assertSame('Rubik Neu (Rubik Bold)', $font->faceFamily($font->faces[1]));

        $variant = $entityManager->find(TemplateVariant::class, Uuid::fromString(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID));
        self::assertInstanceOf(TemplateVariant::class, $variant);
        // The designed headline family and the tagline's allowlist both follow.
        self::assertStringContainsString('"Rubik Neu (Rubik Bold)"', $variant->canvas);
        self::assertStringNotContainsString('"Rubik (Rubik Bold)"', $variant->canvas);
        $tagline = null;
        foreach ($variant->inputs as $input) {
            if ($input->inputId === TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID) {
                $tagline = $input;
            }
        }
        self::assertNotNull($tagline);
        self::assertSame(['Rubik Neu (Rubik Bold)'], $tagline->allowedFonts);
    }

    public function testRenameToAnExistingFamilyIsRefusedAndTheOwnNameIsANoOp(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // Renaming to its own name changes nothing and never collides with itself.
        $bus->dispatch(new RenameFont(Uuid::fromString(TestDataFixture::FONT_RUBIK_ID), 'Rubik'));
        $entityManager->flush();
        self::assertSame('Rubik', self::getContainer()->get(GetFonts::class)->byName(Uuid::fromString(TestDataFixture::PROJECT_1_ID), 'Rubik')->name);

        // A second family in the same project makes its name taken.
        $project = $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);
        $entityManager->persist(new Font(Uuid::uuid4(), $project, new \DateTimeImmutable(), 'Lato', new FontFace('Lato Regular', 400, 'normal', 'fixtures/fonts/lato.ttf')));
        $entityManager->flush();

        try {
            $bus->dispatch(new RenameFont(Uuid::fromString(TestDataFixture::FONT_RUBIK_ID), 'Lato'));
            self::fail('Expected FontNameTaken');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(FontNameTaken::class, $exception->getPrevious());
        }
    }
}
