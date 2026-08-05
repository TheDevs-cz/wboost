<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\InvalidMcpScopes;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpScopeSelection;

/**
 * The `--scopes` parser behind `app:mcp:token:create`, tested as the pure
 * function it is — no kernel, no console.
 *
 * The contract worth locking is the STRICTNESS, which is the opposite of
 * {@see McpScope::fromStrings()} (covered in {@see McpScopeTest}): a stored row
 * with an unknown scope must degrade to "not granted", but a human who typed
 * one must be told, because a token quietly issued with fewer powers than asked
 * for only surfaces days later as an agent that mysteriously cannot export.
 */
final class McpScopeSelectionTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('acceptedInputs')]
    public function testParsesInputIntoRawScopeValues(string $input, array $expected): void
    {
        self::assertSame($expected, McpScopeSelection::parse($input));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function acceptedInputs(): iterable
    {
        yield 'a single scope' => [
            'templates:read',
            ['templates:read'],
        ];

        // Order is preserved rather than normalised: what the operator asked
        // for is what the row stores, and the listing echoes it back verbatim.
        yield 'several scopes, in the order given' => [
            'templates:export,templates:read',
            ['templates:export', 'templates:read'],
        ];

        yield 'whitespace around the separators is trimmed' => [
            "  templates:read ,\ttemplates:export  ",
            ['templates:read', 'templates:export'],
        ];

        yield 'duplicates collapse to the first occurrence' => [
            'templates:read,gallery:write,templates:read',
            ['templates:read', 'gallery:write'],
        ];

        // Typing noise, not an attempt to name a scope.
        yield 'empty segments are ignored' => [
            'templates:read,,templates:export,',
            ['templates:read', 'templates:export'],
        ];
    }

    /**
     * Every declared scope must be reachable from the command line — a case
     * added to the enum with an un-typeable value would be invisible.
     */
    #[DataProvider('scopes')]
    public function testEveryDeclaredScopeIsAcceptedByItsWireValue(McpScope $scope): void
    {
        self::assertSame([$scope->value], McpScopeSelection::parse($scope->value));
    }

    /**
     * @return iterable<string, array{McpScope}>
     */
    public static function scopes(): iterable
    {
        foreach (McpScope::cases() as $scope) {
            yield $scope->value => [$scope];
        }
    }

    public function testUnknownScopeIsRefused(): void
    {
        $this->expectException(InvalidMcpScopes::class);
        $this->expectExceptionMessage('Unknown scope "templates:reed"');

        McpScopeSelection::parse('templates:read,templates:reed');
    }

    /**
     * The message has to carry the answer, not just the complaint: the mistake
     * is nearly always a typo of a scope that does exist.
     */
    public function testTheRefusalNamesEveryValidScope(): void
    {
        try {
            McpScopeSelection::parse('templates:reed');

            self::fail('An unknown scope must be refused.');
        } catch (InvalidMcpScopes $exception) {
            foreach (McpScope::cases() as $scope) {
                self::assertStringContainsString(
                    $scope->value,
                    $exception->getMessage(),
                    sprintf('The error must offer %s as a valid scope.', $scope->value),
                );
            }
        }
    }

    /**
     * A token with no scopes at all authenticates and can do nothing — a
     * confusing way to fail. It is refused at the input instead.
     */
    #[DataProvider('emptySelections')]
    public function testAnEmptySelectionIsRefused(string $input): void
    {
        $this->expectException(InvalidMcpScopes::class);
        $this->expectExceptionMessage('At least one scope is required');

        McpScopeSelection::parse($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptySelections(): iterable
    {
        yield 'empty string' => [''];
        yield 'only whitespace' => ['   '];
        yield 'only separators' => [', ,,'];
    }

    /**
     * The default must be the LEAST powerful scope: a token created in a hurry
     * (no `--scopes`) must not be able to author designs, export or upload.
     */
    public function testTheDefaultIsReadOnly(): void
    {
        self::assertSame(
            [McpScope::TemplatesRead->value],
            McpScopeSelection::parse(McpScopeSelection::DEFAULT_SCOPES),
        );

        self::assertSame([McpScope::TemplatesRead], McpScope::TemplatesRead->grants());
    }
}
