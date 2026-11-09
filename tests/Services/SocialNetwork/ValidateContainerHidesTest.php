<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidExportValue;
use WBoost\Web\Services\SocialNetwork\ValidateContainerHides;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;

/**
 * @covers \WBoost\Web\Services\SocialNetwork\ValidateContainerHides
 */
final class ValidateContainerHidesTest extends TestCase
{
    public function testAcceptsHideOnHidableAndShowOnAnyContainerAndIgnoresUnknownIds(): void
    {
        $this->expectNotToPerformAssertions();
        ValidateContainerHides::strict($this->containers(), [$this->input()], [
            'bubble' => ['hide' => true],
            'fixed' => ['hide' => false],
            'text-1' => ['value' => 'Ahoj', 'hide' => false],
            'stale-or-other-dimension' => ['hide' => true],
        ]);
    }

    public function testRefusesHidingANonHidableContainer(): void
    {
        try {
            ValidateContainerHides::strict($this->containers(), [], ['fixed' => ['hide' => true]]);
            self::fail('Expected a 400.');
        } catch (InvalidExportValue $e) {
            self::assertSame('container_not_hidable', $e->errorCode);
            self::assertSame(['containerId' => 'fixed'], $e->context);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedEntries(): iterable
    {
        yield 'string' => ['text'];
        yield 'value' => [['value' => 'x']];
        yield 'hide plus value' => [['hide' => true, 'value' => 'x']];
        yield 'non-bool hide' => [['hide' => '1']];
        yield 'empty object' => [[]];
    }

    #[DataProvider('malformedEntries')]
    public function testRefusesMalformedContainerEntries(mixed $entry): void
    {
        try {
            ValidateContainerHides::strict($this->containers(), [], ['bubble' => $entry]);
            self::fail('Expected a 400.');
        } catch (InvalidExportValue $e) {
            self::assertSame('invalid_value', $e->errorCode);
            self::assertSame('bubble', $e->context['containerId'] ?? null);
        }
    }

    /**
     * @return list<CanvasContainer>
     */
    private function containers(): array
    {
        return CanvasContainer::collectionFromCanvas(['containers' => [
            ['id' => 'bubble', 'maxHeight' => 100, 'memberInputIds' => ['text-1', 'img'], 'hidable' => true, 'name' => 'Bublina'],
            ['id' => 'fixed', 'maxHeight' => 100, 'memberInputIds' => ['x', 'y']],
        ]]);
    }

    private function input(): EditorTextInput
    {
        return new EditorTextInput(
            inputId: 'text-1',
            name: 'Text',
            maxLength: null,
            locked: false,
            uppercase: false,
            description: null,
            hidable: false,
        );
    }
}
