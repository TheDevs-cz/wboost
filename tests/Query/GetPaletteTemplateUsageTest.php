<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Query;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Query\GetPaletteTemplateUsage;
use WBoost\Web\Value\PaletteTemplateUsage;

/**
 * Template usage of palette HEXes (F7 P4): what the scan counts, per
 * template / document, and the Czech plural of the card chip.
 *
 * @covers \WBoost\Web\Query\GetPaletteTemplateUsage
 * @covers \WBoost\Web\Value\PaletteTemplateUsage
 */
final class GetPaletteTemplateUsageTest extends TestCase
{
    public function testCountsPaintsAllowlistsAndSampleRunsPerTemplate(): void
    {
        $canvas = json_encode([
            'background' => '#FFFFFF',
            'objects' => [
                // A textbox in a brand colour with a per-character override.
                ['type' => 'textbox', 'fill' => '#004E7C', 'styles' => [['0' => ['fill' => '#c8102e']]]],
                // A recolourable shape: designed fill + its allowlist.
                ['type' => 'rect', 'fill' => '#ABC', 'stroke' => 'transparent', 'colorChoice' => 'fill', 'allowedColors' => ['#123456', 'nope']],
                // Nested group with a gradient.
                ['type' => 'group', 'objects' => [['type' => 'rect', 'fill' => ['type' => 'linear', 'colorStops' => [['offset' => 0, 'color' => '#00FF00']]]]]],
                // Not a paint / not a HEX.
                ['type' => 'image', 'src' => '#999999', 'fill' => 'rgb(1,2,3)'],
            ],
        ], JSON_THROW_ON_ERROR);
        $inputs = json_encode([
            ['inputId' => 'a', 'allowedColors' => ['#654321']],
            ['inputId' => 'b', 'allowedColors' => null, 'sampleValue' => json_encode(['runs' => [['text' => 'x', 'color' => '#FEDCBA']]], JSON_THROW_ON_ERROR)],
        ], JSON_THROW_ON_ERROR);

        $usage = GetPaletteTemplateUsage::compute([
            ['unit_id' => 't1', 'unit_name' => 'Plakát', 'canvas' => $canvas, 'inputs' => $inputs],
            // A second variant (another dimension) of the same template counts once.
            ['unit_id' => 't1', 'unit_name' => 'Plakát', 'canvas' => '{"objects":[{"type":"textbox","fill":"#004e7c"}]}', 'inputs' => '[]'],
            ['unit_id' => 't2', 'unit_name' => 'Leták', 'canvas' => '{"objects":[{"type":"textbox","fill":"#004E7C"}]}', 'inputs' => '[]'],
            // Pages of one document = one unit.
            ['unit_id' => 'd1', 'unit_name' => 'Výroční zpráva', 'canvas' => '{"objects":[{"type":"textbox","fill":"#004E7C"}]}', 'inputs' => '[]'],
            ['unit_id' => 'd1', 'unit_name' => 'Výroční zpráva', 'canvas' => '{"objects":[{"type":"rect","fill":"#004E7C"}]}', 'inputs' => '[]'],
            ['unit_id' => 't3', 'unit_name' => 'Broken', 'canvas' => 'not json', 'inputs' => null],
        ]);

        self::assertSame(['Leták', 'Plakát', 'Výroční zpráva'], $usage->templatesFor('#004e7c'));
        self::assertSame('3 šablony', $usage->label('#004E7C'));
        foreach (['#ffffff', '#c8102e', '#aabbcc', '#123456', '#00ff00', '#654321', '#fedcba'] as $hexKey) {
            self::assertSame(['Plakát'], $usage->templatesFor($hexKey), $hexKey);
        }
        self::assertSame('1 šablona', $usage->label('#ffffff'));
        self::assertSame([], $usage->templatesFor('#999999'));
        self::assertSame([], $usage->templatesFor('#010203'));
        self::assertNull($usage->label('#999999'));
    }

    public function testCzechPlural(): void
    {
        self::assertSame('1 šablona', PaletteTemplateUsage::countLabel(1));
        self::assertSame('2 šablony', PaletteTemplateUsage::countLabel(2));
        self::assertSame('4 šablony', PaletteTemplateUsage::countLabel(4));
        self::assertSame('5 šablon', PaletteTemplateUsage::countLabel(5));
        self::assertSame('12 šablon', PaletteTemplateUsage::countLabel(12));
        self::assertSame('0 šablon', PaletteTemplateUsage::countLabel(0));
    }
}
