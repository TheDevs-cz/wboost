<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Fill;

use PHPUnit\Framework\TestCase;
use WBoost\Web\Exceptions\ContainerOverflow;
use WBoost\Web\Mcp\Fill\VariantFill;
use WBoost\Web\Value\CanvasContainer;
use WBoost\Web\Value\EditorTextInput;

/**
 * {@see VariantFill::containerOverflowMessage()} — the translation from
 * "container `9f3…` overflows by 12 px" into a sentence an agent can act on.
 *
 * Driven directly rather than through `/_mcp` because the interesting cases are
 * SHAPES of a container, not fills: a container with exactly one fillable
 * member, and one with none at all. Neither exists in `TestDataFixture` — and
 * adding them there to reach two branches would change the counts and
 * positional input indexes half a dozen other suites assert against.
 *
 * The tool-level suites ({@see \WBoost\Web\Tests\Mcp\Tool\ExportVariantTest})
 * cover the multi-member case end to end, including that the message really
 * reaches the client as a tool error.
 */
final class VariantFillTest extends TestCase
{
    private const string INTRO_ID = '00000000-0000-0000-0000-0000000000a1';

    private const string BODY_ID = '00000000-0000-0000-0000-0000000000a2';

    private const string DECORATION_ID = '00000000-0000-0000-0000-0000000000a3';

    private const string ROOT_ID = 'root-container';

    private const string CHILD_ID = 'child-container';

    /**
     * The case the plan wrote the requirement around: ONE fillable input in the
     * container, so the culprit is not in doubt and the message says so in the
     * singular.
     *
     * The container is not degenerate — it has two members — but the second is
     * a decorative image, which carries an inputId in the canvas and is not a
     * fillable input. That asymmetry is exactly why the members are narrowed to
     * the variant's listed inputs before anything is said about them.
     */
    public function testASingleFillableMemberIsNamedInTheSingular(): void
    {
        $message = VariantFill::containerOverflowMessage(
            [new CanvasContainer(self::ROOT_ID, 320.0, [self::INTRO_ID, self::DECORATION_ID])],
            [self::input(self::INTRO_ID, 'Popis')],
            new ContainerOverflow(self::ROOT_ID, 12.0),
        );

        self::assertStringContainsString('Input "Popis" (id ' . self::INTRO_ID . ') overflows its container by 12 px', $message);
        self::assertStringContainsString('allows 320 px of content', $message);
        self::assertStringContainsString('Shorten that text', $message);
        self::assertStringNotContainsString(self::DECORATION_ID, $message);
    }

    /**
     * Several members and no way to tell which one is too long — they share one
     * vertical flow, and the overflow is measured on the flow, not per input.
     * Naming one of them anyway would be a guess an agent would act on.
     */
    public function testSeveralFillableMembersAreListedWithoutGuessingTheCulprit(): void
    {
        $message = VariantFill::containerOverflowMessage(
            [new CanvasContainer(self::ROOT_ID, 320.0, [self::INTRO_ID, self::BODY_ID])],
            [self::input(self::INTRO_ID, 'Popis'), self::input(self::BODY_ID, 'Text')],
            new ContainerOverflow(self::ROOT_ID, 12.5),
        );

        self::assertStringContainsString('overflow its max height of 320 px by 12.5 px', $message);
        self::assertStringContainsString('"Popis" (id ' . self::INTRO_ID . ')', $message);
        self::assertStringContainsString('"Text" (id ' . self::BODY_ID . ')', $message);
        self::assertStringContainsString('cannot be told apart here', $message);
    }

    /**
     * Overflow is always reported on the ROOT container, whose fillable inputs
     * may sit any number of levels down — a nested child flows inside its
     * parent as one item. A message that listed only the root's OWN members
     * would omit precisely the texts the agent has to shorten.
     */
    public function testMembersOfNestedChildrenAreNamedToo(): void
    {
        $message = VariantFill::containerOverflowMessage(
            [
                new CanvasContainer(self::ROOT_ID, 700.0, [self::INTRO_ID], [self::CHILD_ID]),
                new CanvasContainer(self::CHILD_ID, 400.0, [self::BODY_ID, self::DECORATION_ID]),
            ],
            [self::input(self::INTRO_ID, 'Popis'), self::input(self::BODY_ID, 'Text')],
            new ContainerOverflow(self::ROOT_ID, 8.0),
        );

        self::assertStringContainsString('"Popis"', $message);
        self::assertStringContainsString('"Text"', $message);
    }

    /**
     * A container whose members are all decorative or design-hidden cannot be
     * fixed by filling anything — saying "shorten one of your texts" would send
     * the agent looking for an input that is not there. The honest answer names
     * the designer.
     */
    public function testAContainerWithNoFillableMemberSaysTheDesignIsAtFault(): void
    {
        $message = VariantFill::containerOverflowMessage(
            [new CanvasContainer(self::ROOT_ID, 320.0, [self::DECORATION_ID, self::BODY_ID])],
            [],
            new ContainerOverflow(self::ROOT_ID, 4.0),
        );

        self::assertStringContainsString('none of its members is a fillable text input', $message);
        self::assertStringContainsString('the overflow comes from the design itself', $message);
    }

    /**
     * A container id the canvas does not describe — a stale definition, or the
     * null id {@see ContainerOverflow::tryFromGotenbergError()} yields from a
     * truncated Gotenberg body. The refusal still has to be a refusal an agent
     * can move on from.
     */
    public function testAnUnlocatableContainerFallsBackToItsIdAndDescribeVariant(): void
    {
        $message = VariantFill::containerOverflowMessage(
            [new CanvasContainer(self::ROOT_ID, 320.0, [self::INTRO_ID, self::BODY_ID])],
            [self::input(self::INTRO_ID, 'Popis')],
            new ContainerOverflow('a-container-nobody-knows', 12.0),
        );

        self::assertStringContainsString('container a-container-nobody-knows', $message);
        self::assertStringContainsString('describe_variant', $message);
        self::assertStringNotContainsString('Popis', $message, 'Nothing may be claimed about a container that was not found.');
    }

    /**
     * An input with no name is still addressable by id, and `"" (id …)` would
     * read as a bug rather than as an unnamed input.
     */
    public function testAnUnnamedInputIsLabelledByItsIdAlone(): void
    {
        $message = VariantFill::containerOverflowMessage(
            [new CanvasContainer(self::ROOT_ID, 320.0, [self::INTRO_ID, self::DECORATION_ID])],
            [self::input(self::INTRO_ID, null)],
            new ContainerOverflow(self::ROOT_ID, 12.0),
        );

        self::assertStringContainsString('(unnamed, id ' . self::INTRO_ID . ')', $message);
        self::assertStringNotContainsString('""', $message);
    }

    /**
     * A hand-edited canvas can hold a cycle the editor would never author. The
     * message builder runs while a render is already being REFUSED — hanging
     * there would replace an actionable error with a request that never ends.
     */
    public function testACyclicNestingTerminates(): void
    {
        $message = VariantFill::containerOverflowMessage(
            [
                new CanvasContainer(self::ROOT_ID, 700.0, [self::INTRO_ID], [self::CHILD_ID]),
                new CanvasContainer(self::CHILD_ID, 400.0, [self::BODY_ID], [self::ROOT_ID]),
            ],
            [self::input(self::INTRO_ID, 'Popis'), self::input(self::BODY_ID, 'Text')],
            new ContainerOverflow(self::ROOT_ID, 8.0),
        );

        self::assertStringContainsString('"Popis"', $message);
        self::assertStringContainsString('"Text"', $message);
    }

    private static function input(string $inputId, null|string $name): EditorTextInput
    {
        return new EditorTextInput($inputId, $name, null, false, false, null, false);
    }
}
