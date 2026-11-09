<?php

declare(strict_types=1);

namespace WBoost\Web\Tests;

use Ramsey\Uuid\Uuid;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\EditorImageInput;
use WBoost\Web\Value\EditorTextInput;
use WBoost\Web\Value\TemplateDimension;

/**
 * The prod container fixtures (tests/Fixtures/containers/*.json — real
 * canvases with the image URLs stripped) as in-memory variants:
 *
 * - `vysledky-turnaje` — Fotbal FM "Výsledky turnaje": a root container
 *   (gap 25, max 419, no own members) holding FIVE nested 2-member rows
 *   ("Skóre N" rich + "Soupeř N"), plus a second root ("Sestava a branky"
 *   locked + "Sestava").
 * - `vysledek` — Fotbal FM "Výsledek" group, 1080×1350 dimension: two
 *   side-by-side column containers of four single hidable texts each
 *   ("D/H - Střelec 1–4", gap 15, max 220).
 *
 * The baseline the container-rows work (P0 of the plan) pins before the
 * fill pages and the engine change.
 */
final class ContainerFixtures
{
    public const string TURNAJ = 'vysledky-turnaje';
    public const string VYSLEDEK = 'vysledek';

    // Výsledky turnaje
    public const string TURNAJ_ROOT = 'de5f8b49-7d6d-481f-97e2-a840ce873f93';
    public const string TURNAJ_ROW_3 = 'c0d9c23d-d90c-401c-aa72-5d43f8778bd8';
    public const string TURNAJ_TEXT_BLOK = 'text-blok';
    public const string TURNAJ_SKORE_1 = '5a191f3b-0ba9-4a95-b09f-d9cb1bab8297';
    public const string TURNAJ_SOUPER_1 = 'aa3dc632-f4bc-4f2d-9ffc-ad52da560589';
    public const string TURNAJ_SOUPER_2 = '57bcb667-9b3d-4e5a-8e0a-16f305911c7c';
    public const string TURNAJ_SKORE_3 = '44d77b20-3237-4af5-b063-9b3c23744855';
    public const string TURNAJ_SOUPER_3 = 'e791a08e-9d60-49ba-bc4e-71043d8a62d1';
    public const string TURNAJ_SESTAVA = '812fbf9c-19b2-4d1b-9d10-20d5d373dfd7';
    public const string TURNAJ_HEADLINE = 'a1249eae-2182-4ef2-aaa8-2bbecb864465';

    // Výsledek (group, 1350 dimension)
    public const string VYSLEDEK_DOMACI = '6573ac4d-5d3b-4be4-9455-12b2f0c76fa7';
    public const string VYSLEDEK_HOSTE = 'e2ade5b7-a8ea-4e92-ac4d-8972b7b2c83b';
    public const string VYSLEDEK_D_STRELEC_1 = '830a61dd-190f-4b6e-a645-40e9d2294a33';
    public const string VYSLEDEK_D_STRELEC_3 = '7375f8f3-1781-42e5-b5af-c7cb357ae763';
    public const string VYSLEDEK_D_STRELEC_4 = 'dc856a12-b515-4229-a1df-8500fd370dbd';

    /**
     * @return array{source: string, width: int, height: int, canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>}
     */
    public static function raw(string $name): array
    {
        $json = file_get_contents(__DIR__ . '/Fixtures/containers/' . $name . '.json');
        \assert(is_string($json));
        /** @var array{source: string, width: int, height: int, canvas: array<string, mixed>, inputs: list<array<string, mixed>>, imageInputs: list<array<string, mixed>>} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * A fresh, NEVER persisted variant carrying the fixture — `$template`
     * only satisfies the entity's owner (any fixture template will do).
     *
     * @param null|callable(array<string, mixed>): array<string, mixed> $editCanvas
     * @param list<string> $dropInputIds text inputs to leave out (their textboxes must be gone too — binding is positional)
     */
    public static function variant(string $name, Template $template, null|callable $editCanvas = null, array $dropInputIds = []): TemplateVariant
    {
        $data = self::raw($name);
        $canvas = $editCanvas !== null ? $editCanvas($data['canvas']) : $data['canvas'];
        $inputs = array_values(array_filter(
            $data['inputs'],
            static fn (array $row): bool => !in_array($row['inputId'] ?? null, $dropInputIds, true),
        ));

        $variant = new TemplateVariant(
            Uuid::uuid7(),
            $template,
            new TemplateDimension(DimensionUnit::Px, $data['width'], $data['height']),
            null,
            new \DateTimeImmutable('2026-11-09 12:00:00'),
        );
        $variant->editCanvas(
            json_encode($canvas, JSON_THROW_ON_ERROR),
            array_map(
                // @phpstan-ignore argument.type (fixture rows are EditorTextInput::toArray() shapes)
                static fn (array $row): EditorTextInput => EditorTextInput::fromArray($row),
                $inputs,
            ),
            null,
            array_map(
                static fn (array $row): EditorImageInput => EditorImageInput::fromArray($row),
                $data['imageInputs'],
            ),
        );

        return $variant;
    }

    // Image slot in a container (plan P2a) — a synthetic 600×900 design.
    public const string SLOT_CONTAINER = 'slot-box';
    public const string SLOT_TITLE = '0193a001-0000-7000-8000-000000000001';
    public const string SLOT_CAPTION = '0193a001-0000-7000-8000-000000000002';
    public const string SLOT_IMAGE = '0193a001-0000-7000-8000-000000000003';
    /** One title line at fontSize 50 / lineHeight 1: 50 × 1.13 (Fabric's _fontSizeMult). */
    public const float SLOT_LINE = 56.5;

    /**
     * A container over three members, top to bottom: a TITLE text (x 320, y
     * 40), a fillable image SLOT (x 40, y 140, 200×200 — vertically clear of
     * both texts, so a standalone flow item) and a CAPTION text (x 320, y
     * 380). Fonts are 50 px at lineHeight 1, so every extra title line is a
     * whole SLOT_LINE × 2 = 113 px for two — an integer shift that keeps
     * pixel crops comparable. The slot's stand-in is an inline PNG
     * (`$standInDataUri`).
     *
     * `$slotTop` moves the slot (e.g. onto the caption's band to make it the
     * caption's ATTACHMENT); `$withSlot: false` leaves the slot out entirely
     * (object, image input and membership) — the "absent" twin of a hidden
     * slot. `$gap` sets the container's uniform gap (null = designed gaps),
     * `$captionTop` the caption's designed top.
     */
    public static function slotVariant(Template $template, string $standInDataUri, float $slotTop = 140.0, bool $withSlot = true, null|float $gap = null, float $captionTop = 380.0): TemplateVariant
    {
        $text = static fn (string $id, float $top, string $value): array => [
            'type' => 'Textbox',
            'version' => '7.3.1',
            'originX' => 'left',
            'originY' => 'top',
            'left' => 320,
            'top' => $top,
            'width' => 240,
            'height' => self::SLOT_LINE,
            'fontSize' => 50,
            'lineHeight' => 1,
            'fontFamily' => 'Arial',
            'fill' => '#000000',
            'text' => $value,
            'inputId' => $id,
        ];

        $objects = [$text(self::SLOT_TITLE, 40.0, 'A')];
        if ($withSlot) {
            $objects[] = [
                'type' => 'Image',
                'version' => '7.3.1',
                'originX' => 'left',
                'originY' => 'top',
                'left' => 40,
                'top' => $slotTop,
                'width' => 100,
                'height' => 100,
                'scaleX' => 2,
                'scaleY' => 2,
                'src' => $standInDataUri,
                'crossOrigin' => null,
                'imagePlaceholder' => true,
                'inputId' => self::SLOT_IMAGE,
            ];
        }
        $objects[] = $text(self::SLOT_CAPTION, $captionTop, 'B');

        $canvas = [
            'version' => '7.3.1',
            'background' => '#ffffff',
            'objects' => $objects,
            'containers' => [[
                'id' => self::SLOT_CONTAINER,
                'maxHeight' => 800,
                'memberInputIds' => $withSlot
                    ? [self::SLOT_TITLE, self::SLOT_IMAGE, self::SLOT_CAPTION]
                    : [self::SLOT_TITLE, self::SLOT_CAPTION],
                'memberContainerIds' => [],
            ] + ($gap !== null ? ['gap' => $gap] : [])],
        ];

        $variant = new TemplateVariant(
            Uuid::uuid7(),
            $template,
            new TemplateDimension(DimensionUnit::Px, 600, 900),
            null,
            new \DateTimeImmutable('2026-11-09 12:00:00'),
        );
        $variant->editCanvas(
            json_encode($canvas, JSON_THROW_ON_ERROR),
            [
                self::textInput(self::SLOT_TITLE, 'Nadpis', 'A', hidable: false),
                self::textInput(self::SLOT_CAPTION, 'Popisek', 'B', hidable: true),
            ],
            null,
            $withSlot
                ? [EditorImageInput::fromArray(['inputId' => self::SLOT_IMAGE, 'name' => 'Logo', 'allowMove' => true, 'allowResize' => true, 'hidable' => true])]
                : [],
        );

        return $variant;
    }

    private static function textInput(string $inputId, string $name, string $sample, bool $hidable): EditorTextInput
    {
        return EditorTextInput::fromArray([
            'inputId' => $inputId, 'name' => $name, 'description' => '', 'sampleValue' => $sample,
            'locked' => false, 'hidable' => $hidable, 'uppercase' => false, 'maxLength' => null,
            'richText' => false, 'lists' => false, 'checklist' => false, 'fontChoice' => false,
            'allowedFonts' => [], 'allowedColors' => null,
        ]);
    }

    /**
     * The fixture as if one nested container had never been designed: its
     * definition, its parent's reference and its members' objects AND
     * inputs are gone.
     */
    public static function variantWithoutContainer(string $name, Template $template, string $containerId): TemplateVariant
    {
        $memberIds = [];
        /** @var list<array<string, mixed>> $containers */
        $containers = self::raw($name)['canvas']['containers'];
        foreach ($containers as $container) {
            if ($container['id'] === $containerId) {
                /** @var list<string> $memberIds */
                $memberIds = $container['memberInputIds'];
            }
        }

        return self::variant(
            $name,
            $template,
            static fn (array $canvas): array => self::withoutContainer($canvas, $containerId),
            $memberIds,
        );
    }

    /**
     * @param array<string, mixed> $canvas
     * @return array<string, mixed>
     */
    private static function withoutContainer(array $canvas, string $containerId): array
    {
        /** @var list<array<string, mixed>> $containers */
        $containers = $canvas['containers'];
        $members = [];
        $kept = [];
        foreach ($containers as $container) {
            if ($container['id'] === $containerId) {
                /** @var list<string> $memberIds */
                $memberIds = $container['memberInputIds'];
                $members = $memberIds;
                continue;
            }
            /** @var list<string> $children */
            $children = $container['memberContainerIds'] ?? [];
            $container['memberContainerIds'] = array_values(array_filter($children, static fn (string $id): bool => $id !== $containerId));
            $kept[] = $container;
        }
        $canvas['containers'] = $kept;

        /** @var list<array<string, mixed>> $objects */
        $objects = $canvas['objects'];
        $canvas['objects'] = array_values(array_filter(
            $objects,
            static fn (array $object): bool => !in_array($object['inputId'] ?? null, $members, true),
        ));

        return $canvas;
    }
}
