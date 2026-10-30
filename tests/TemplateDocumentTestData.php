<?php

declare(strict_types=1);

namespace WBoost\Web\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Message\TemplateDocument\CreateTemplateDocument;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\TemplateDimension;

/**
 * Builds multi-page documents for tests in-test (DAMA rolls them back), so
 * the shared fixture's template counts stay untouched.
 */
final class TemplateDocumentTestData
{
    /** Every helper page carries THIS input id — like duplicated templates do. */
    public const string SHARED_INPUT_ID = '22222222-0000-0000-0000-000000000001';

    /**
     * @return array{template: UuidInterface, variant: UuidInterface}
     */
    public static function a4Template(EntityManagerInterface $em, string $name): array
    {
        $project = $em->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        assert($project instanceof Project);

        $template = new Template(Uuid::uuid7(), $project, null, new \DateTimeImmutable(), $name, null, 0);
        $variant = new TemplateVariant(Uuid::uuid7(), $template, new TemplateDimension(DimensionUnit::Mm, 210, 297), null, new \DateTimeImmutable());
        $variant->editCanvas(
            '{"version":"5.2.4","objects":[{"type":"Textbox","left":100,"top":100,"width":800,"height":120,"scaleX":1,"scaleY":1,"originX":"left","originY":"top","text":"Ahoj"}]}',
            [new EditorTextInput(self::SHARED_INPUT_ID, 'Jméno', null, false, false, null, false)],
            null,
        );

        $em->persist($template);
        $em->persist($variant);
        $em->flush();
        // The fresh Template's in-memory variants collection is empty until
        // reloaded — clear so later lookups see the variant.
        $em->clear();

        return ['template' => $template->id, 'variant' => $variant->id];
    }

    /**
     * @param list<UuidInterface> $templateIds
     */
    public static function document(EntityManagerInterface $em, MessageBusInterface $bus, array $templateIds, string $name = 'Nabídka'): UuidInterface
    {
        $documentId = Uuid::uuid7();
        $bus->dispatch(new CreateTemplateDocument(
            $documentId,
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $name,
            null,
            new TemplateDimension(DimensionUnit::Mm, 210, 297, null, 3.0, true),
            $templateIds,
        ));

        $em->clear();

        return $documentId;
    }
}
