<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\SocialNetwork;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Value\EditorTextInput;

/**
 * @covers \WBoost\Web\Services\SocialNetwork\ResolveTextOverrides
 */
final class ResolveTextOverridesTest extends TestCase
{
    private const INPUT_ID = '11111111-1111-4111-8111-111111111111';

    public function testThrowsByDefaultWhenValueExceedsMaxLength(): void
    {
        $this->expectException(BadRequestHttpException::class);

        (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 5)],
            [self::INPUT_ID => 'abcdefgh'],
        );
    }

    public function testTruncatesToMaxLengthWhenTruncateOverflowRequested(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 5)],
            [self::INPUT_ID => 'abcdefgh'],
            truncateOverflow: true,
        );

        self::assertSame('abcde', $result->texts[self::INPUT_ID]);
    }

    public function testTruncationCountsMultibyteCharacters(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 3)],
            [self::INPUT_ID => 'ěščřž'],
            truncateOverflow: true,
        );

        self::assertSame('ěšč', $result->texts[self::INPUT_ID]);
    }

    public function testTruncationHappensBeforeUppercasing(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 3, uppercase: true)],
            [self::INPUT_ID => 'abcdef'],
            truncateOverflow: true,
        );

        self::assertSame('ABC', $result->texts[self::INPUT_ID]);
    }

    public function testValueWithinLimitIsLeftUntouched(): void
    {
        $result = (new ResolveTextOverrides())->resolve(
            [$this->input(maxLength: 5)],
            [self::INPUT_ID => 'abc'],
            truncateOverflow: true,
        );

        self::assertSame('abc', $result->texts[self::INPUT_ID]);
    }

    private function input(int $maxLength, bool $uppercase = false): EditorTextInput
    {
        return new EditorTextInput(
            inputId: self::INPUT_ID,
            name: 'Headline',
            maxLength: $maxLength,
            locked: false,
            uppercase: $uppercase,
            description: null,
            hidable: false,
        );
    }
}
