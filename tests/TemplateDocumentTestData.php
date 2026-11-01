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
use WBoost\Web\Value\EditorImageInput;
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

    /** The recolourable shape every {@see kindA4Template()} page carries. */
    public const string SHAPE_INPUT_ID = '22222222-0000-0000-0000-0000000000b1';

    /** The image slot every {@see richA4Template()} page carries (also colliding). */
    public const string IMAGE_INPUT_ID = '22222222-0000-0000-0000-0000000000a1';

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

    /**
     * A page closer to a real one: `$texts` hidable text inputs (the first
     * one is {@see SHARED_INPUT_ID}, the rest deterministic per index — so
     * every rich page collides on ALL of them, like duplicates do), `$images`
     * adjustable + hidable image slots (the first is {@see IMAGE_INPUT_ID})
     * and a container over the first two texts.
     *
     * @return array{template: UuidInterface, variant: UuidInterface}
     */
    public static function richA4Template(EntityManagerInterface $em, string $name, int $texts = 2, int $images = 1): array
    {
        $project = $em->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        assert($project instanceof Project);

        $template = new Template(Uuid::uuid7(), $project, null, new \DateTimeImmutable(), $name, null, 0);
        $variant = new TemplateVariant(Uuid::uuid7(), $template, new TemplateDimension(DimensionUnit::Mm, 210, 297), null, new \DateTimeImmutable());

        $objects = [];
        $inputs = [];
        $textIds = [];
        for ($i = 0; $i < $texts; $i++) {
            $inputId = $i === 0 ? self::SHARED_INPUT_ID : sprintf('22222222-0000-0000-0001-%012d', $i);
            $textIds[] = $inputId;
            $objects[] = ['type' => 'Textbox', 'inputId' => $inputId, 'left' => 100, 'top' => 100 + $i * 130, 'width' => 800, 'height' => 120, 'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top', 'text' => 'Text ' . $i, 'fontSize' => 40];
            $inputs[] = EditorTextInput::fromArray(['inputId' => $inputId, 'name' => $i === 0 ? 'Jméno' : 'Pole ' . $i, 'maxLength' => $i === 0 ? 60 : null, 'locked' => false, 'hidable' => true, 'sampleValue' => 'Ukázka ' . $i]);
        }

        $imageInputs = [];
        for ($i = 0; $i < $images; $i++) {
            $inputId = $i === 0 ? self::IMAGE_INPUT_ID : sprintf('22222222-0000-0000-0002-%012d', $i);
            $objects[] = ['type' => 'Image', 'imagePlaceholder' => true, 'inputId' => $inputId, 'left' => 1000, 'top' => 100 + $i * 400, 'width' => 400, 'height' => 300, 'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top', 'src' => 'https://example.test/stand-in.png'];
            $imageInputs[] = EditorImageInput::fromArray(['inputId' => $inputId, 'name' => 'Foto ' . $i, 'allowMove' => true, 'allowResize' => true, 'allowRotate' => true, 'hidable' => true, 'allowedDirectoryIds' => []]);
        }

        $canvas = ['version' => '5.2.4', 'objects' => $objects];
        if (count($textIds) >= 2) {
            $canvas['containers'] = [['id' => 'container-1', 'maxHeight' => 900.0, 'memberInputIds' => [$textIds[0], $textIds[1]], 'memberContainerIds' => []]];
        }

        $variant->editCanvas((string) json_encode($canvas), $inputs, null, $imageInputs);

        $em->persist($template);
        $em->persist($variant);
        $em->flush();
        $em->clear();

        return ['template' => $template->id, 'variant' => $variant->id];
    }

    /**
     * A page whose {@see SHARED_INPUT_ID} text input has the given editor
     * flags (`richText`, `lists`, `checklist`, `allowedColors` …), plus a
     * recolourable rectangle ({@see SHAPE_INPUT_ID}) allowing `$shapeColors`.
     *
     * @param array{richText?: bool, lists?: bool, listCheckboxes?: bool, checklist?: bool, checklistAdd?: bool, allowedColors?: null|list<string>} $textFlags
     * @param list<string> $shapeColors
     * @return array{template: UuidInterface, variant: UuidInterface}
     */
    public static function kindA4Template(EntityManagerInterface $em, string $name, array $textFlags, array $shapeColors = ['#111111', '#222222']): array
    {
        $project = $em->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        assert($project instanceof Project);

        $template = new Template(Uuid::uuid7(), $project, null, new \DateTimeImmutable(), $name, null, 0);
        $variant = new TemplateVariant(Uuid::uuid7(), $template, new TemplateDimension(DimensionUnit::Mm, 210, 297), null, new \DateTimeImmutable());
        $canvas = ['version' => '5.2.4', 'objects' => [
            ['type' => 'Textbox', 'inputId' => self::SHARED_INPUT_ID, 'left' => 100, 'top' => 100, 'width' => 800, 'height' => 120, 'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top', 'text' => 'Ahoj', 'fontSize' => 40],
            ['type' => 'Rect', 'inputId' => self::SHAPE_INPUT_ID, 'name' => 'Pruh', 'colorChoice' => 'fill', 'fill' => '#ff0000', 'allowedColors' => $shapeColors, 'left' => 0, 'top' => 0, 'width' => 200, 'height' => 50, 'scaleX' => 1, 'scaleY' => 1, 'originX' => 'left', 'originY' => 'top'],
        ]];
        $variant->editCanvas(
            (string) json_encode($canvas),
            [EditorTextInput::fromArray(['inputId' => self::SHARED_INPUT_ID, 'name' => 'Jméno', 'maxLength' => null, 'locked' => false, 'hidable' => true] + $textFlags)],
            null,
        );

        $em->persist($template);
        $em->persist($variant);
        $em->flush();
        $em->clear();

        return ['template' => $template->id, 'variant' => $variant->id];
    }
}
