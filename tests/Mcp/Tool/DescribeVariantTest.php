<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;

/**
 * `describe_variant` (S2-T3) — driven end to end over `/_mcp`, never as a bare
 * service call: the SDK derives a tool's input schema from reflection at
 * COMPILE time, so a tool can be perfectly correct in isolation and still fail
 * to register.
 *
 * ## The cross-check is the point of this suite
 *
 * {@see testInputIdsAndFramesAgreeWithTheRestApiListing()} drives BOTH surfaces
 * in one process — `/_mcp` and `GET /api/projects/{id}/templates` — over every
 * variant of the fixture project and compares the ids and the frames. They
 * describe the same design, and an agent handed a frame the renderer does not
 * draw at puts its highlight on the wrong pixels; "both return four inputs" is
 * exactly the weaker property that would pass while the numbers disagreed.
 *
 * The other load-bearing case is {@see testForeignVariantIsIndistinguishableFromAnUnknownId()}:
 * a variant the caller may not see and an id that matches nothing must produce
 * the SAME words, or any token becomes an id-probing oracle.
 */
final class DescribeVariantTest extends WebTestCase
{
    /**
     * One browser per test method, created on first use — `createClient()` may
     * only be called once per test, and several cases here make two calls.
     */
    private null|KernelBrowser $browser = null;

    /**
     * Identity + size, on the variant the whole suite orients around.
     */
    public function testDescribesTheVariantItsTemplateAndItsProject(): void
    {
        $result = $this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertSame(TestDataFixture::ORIENTATION_VARIANT_ID, $result['variantId']);
        self::assertSame(TestDataFixture::ORIENTATION_TEMPLATE_ID, $result['templateId']);
        self::assertSame('Orientation Template', $result['templateName']);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $result['projectId']);
        self::assertSame('Project 1', $result['projectName']);
        self::assertFalse($result['grouped']);

        self::assertSame(
            ['label' => '1:1', 'preset' => '1:1', 'unit' => 'px', 'unitWidth' => 1080, 'unitHeight' => 1080, 'width' => 1080, 'height' => 1080],
            $result['dimension'],
            'Dimensions read with the same keys get_context and find_templates already used.',
        );
    }

    /**
     * THE Done-when. Every variant of the project, both surfaces, in one
     * process: the same input ids in the same order, and the same frames to the
     * pixel. A drift here is a highlight box drawn where the renderer does not
     * draw the text.
     */
    public function testInputIdsAndFramesAgreeWithTheRestApiListing(): void
    {
        $api = $this->apiVariantsById(TestDataFixture::PROJECT_1_ID);
        self::assertNotSame([], $api, 'The API listing returned no variants — the comparison would be vacuous.');

        $compared = 0;

        foreach ($api as $variantId => $apiVariant) {
            $mcp = $this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, $variantId);

            self::assertSame(
                self::idsAndFrames($apiVariant['inputs'] ?? null),
                self::idsAndFrames($mcp['inputs'] ?? null),
                'Text input ids/frames disagree with the REST listing for variant ' . $variantId,
            );

            self::assertSame(
                self::idsAndFrames($apiVariant['imageInputs'] ?? null),
                self::idsAndFrames($mcp['imageInputs'] ?? null),
                'Image input ids/frames disagree with the REST listing for variant ' . $variantId,
            );

            $compared++;
        }

        self::assertGreaterThanOrEqual(5, $compared);
    }

    /**
     * Containers are the other half of that contract — the zone anchor `y` and
     * the published membership come from ONE implementation shared with the
     * REST listing, and this is what says so out loud.
     */
    public function testContainersAgreeWithTheRestApiListing(): void
    {
        foreach ($this->apiVariantsById(TestDataFixture::PROJECT_1_ID) as $variantId => $apiVariant) {
            $mcp = $this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, $variantId);

            self::assertSame(
                self::numbersAsFloats($apiVariant['containers'] ?? null),
                self::numbersAsFloats($mcp['containers'] ?? null),
                'Containers disagree with the REST listing for variant ' . $variantId,
            );
        }
    }

    /**
     * Every text-input field, on the three inputs of the orientation variant —
     * one plain, one WYSIWYG-with-lists, one checklist component.
     */
    public function testTextInputsReportTheirConstraintsAndCapabilities(): void
    {
        $inputs = self::inputsById($this->describe(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::ORIENTATION_VARIANT_ID,
        ), 'inputs');

        self::assertSame(
            [
                TestDataFixture::ORIENTATION_INPUT_INTRO_ID,
                TestDataFixture::ORIENTATION_INPUT_BULLETS_ID,
                TestDataFixture::ORIENTATION_INPUT_CHECKLIST_ID,
            ],
            array_keys($inputs),
            'Inputs keep the designer order, which is also the REST listing order.',
        );

        self::assertSame(
            [
                'id' => TestDataFixture::ORIENTATION_INPUT_INTRO_ID,
                'name' => 'intro',
                'description' => 'Lead paragraph, one or two sentences',
                'maxLength' => 120,
                'uppercase' => false,
                'locked' => false,
                'hidable' => false,
                'richText' => false,
                'lists' => false,
                'listCheckboxes' => false,
                'checklist' => null,
                'sampleValue' => 'Welcome to the show',
                // 2nd canvas object; the design-hidden textbox between it and
                // the next input must NOT have shifted the binding.
                'frame' => ['x' => 80, 'y' => 100, 'width' => 520, 'height' => 80],
                'containerId' => TestDataFixture::ORIENTATION_ROOT_CONTAINER_ID,
                'fontOptions' => null,
                'colorOptions' => null,
            ],
            $inputs[TestDataFixture::ORIENTATION_INPUT_INTRO_ID],
        );

        $bullets = $inputs[TestDataFixture::ORIENTATION_INPUT_BULLETS_ID];
        self::assertTrue($bullets['richText']);
        self::assertTrue($bullets['lists']);
        self::assertTrue($bullets['listCheckboxes']);
        self::assertNull($bullets['checklist']);
        self::assertTrue($bullets['hidable']);
        // 4th canvas object — one PAST the hidden textbox, which is the whole
        // point: an invisible layer is not fillable and takes no input slot.
        self::assertSame(['x' => 80, 'y' => 220, 'width' => 520, 'height' => 160], $bullets['frame']);

        $checklist = $inputs[TestDataFixture::ORIENTATION_INPUT_CHECKLIST_ID];
        self::assertSame(
            ['toggle' => true, 'editText' => true, 'addItems' => false, 'removeItems' => true],
            $checklist['checklist'],
            'A checklist reports its four capabilities individually — not one "is a checklist" bit.',
        );
        self::assertSame(
            '{"runs":[{"text":"First task\nSecond task"}],"lines":["cb","cbx"]}',
            $checklist['sampleValue'],
            'The sample is the raw wire value, so it can be sent back verbatim.',
        );
    }

    /**
     * The nested pair: only the ROOT bounds the flow, the child is marked
     * `nested`, and both anchor at the highest designed member of their TREE.
     */
    public function testContainersReportTheNestedTreeWithItsAnchors(): void
    {
        $containers = self::inputsById($this->describe(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::ORIENTATION_VARIANT_ID,
        ), 'containers');

        self::assertSame(
            [
                'id' => TestDataFixture::ORIENTATION_ROOT_CONTAINER_ID,
                'maxHeight' => 700,
                // The intro (y=100) sits above the child's own anchor (y=220).
                'y' => 100,
                'memberInputIds' => [TestDataFixture::ORIENTATION_INPUT_INTRO_ID],
                'memberContainerIds' => [TestDataFixture::ORIENTATION_NESTED_CONTAINER_ID],
                'gap' => null,
                'spaceAfter' => 40,
                'nested' => false,
            ],
            $containers[TestDataFixture::ORIENTATION_ROOT_CONTAINER_ID],
        );

        self::assertSame(
            [
                'id' => TestDataFixture::ORIENTATION_NESTED_CONTAINER_ID,
                'maxHeight' => 400,
                'y' => 220,
                'memberInputIds' => [
                    TestDataFixture::ORIENTATION_INPUT_BULLETS_ID,
                    TestDataFixture::ORIENTATION_INPUT_CHECKLIST_ID,
                ],
                'memberContainerIds' => [],
                'gap' => 24,
                'spaceAfter' => null,
                'nested' => true,
            ],
            $containers[TestDataFixture::ORIENTATION_NESTED_CONTAINER_ID],
        );
    }

    /**
     * A variant with no containers at all reports an empty list, not a missing
     * key — an agent must not have to distinguish "no containers" from "this
     * server does not tell me".
     */
    public function testVariantWithoutContainersReportsAnEmptyList(): void
    {
        $result = $this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        self::assertSame([], $result['containers']);
    }

    /**
     * The allowed-folders rule, both branches, on one variant — this is the
     * field an agent picks a picture with, and reading the empty allow-list as
     * "nothing is allowed" would tell it the slot is unusable.
     */
    public function testImageInputsReportTheirSlotLimitsAndAllowedFolders(): void
    {
        $images = self::inputsById($this->describe(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::ORIENTATION_VARIANT_ID,
        ), 'imageInputs');

        $background = $images[TestDataFixture::ORIENTATION_IMAGE_BACKGROUND_ID];
        self::assertTrue($background['isBackground']);
        self::assertTrue($background['hidable']);
        // A background fill is a fixed cover — no transform is ever accepted.
        self::assertFalse($background['allowMove']);
        self::assertFalse($background['allowResize']);
        self::assertFalse($background['allowRotate']);
        // The frame is the CANVAS, not the designed 1200×1100 object box.
        self::assertSame(['x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1080], $background['frame']);
        self::assertSame(
            [['id' => TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, 'name' => 'Photos']],
            $background['directories'],
        );
        self::assertFalse(
            $background['includesRoot'],
            'An explicit allow-list can only name folders, so it always excludes the gallery root.',
        );

        $photo = $images[TestDataFixture::ORIENTATION_IMAGE_FREE_ID];
        self::assertFalse($photo['isBackground']);
        self::assertTrue($photo['allowMove']);
        self::assertTrue($photo['allowResize']);
        self::assertTrue($photo['allowRotate']);
        self::assertSame(['x' => 620, 'y' => 220, 'width' => 360, 'height' => 360], $photo['frame']);

        // Unrestricted: EVERY project folder, plus the root.
        self::assertTrue($photo['includesRoot']);
        $directories = $photo['directories'];
        self::assertIsArray($directories);
        // Narrowed by the assertSame above — the restricted slot lists exactly
        // the one folder.
        $restricted = $background['directories'];
        self::assertGreaterThan(
            count($restricted),
            count($directories),
            'An empty allow-list must open the WHOLE gallery, not close it.',
        );
        self::assertContains(
            ['id' => TestDataFixture::FILE_DIRECTORY_OTHER_ID, 'name' => 'Other'],
            $directories,
        );
    }

    /**
     * `inputs[].fontOptions` — the per-input font offer — is the REST
     * listing's `fontOptions[].family` list, so an agent and a browser
     * consumer read the same offer for the same input.
     */
    public function testFontOptionsAgreeWithTheRestApiListing(): void
    {
        $seen = 0;

        foreach ($this->apiVariantsById(TestDataFixture::PROJECT_1_ID) as $variantId => $apiVariant) {
            $mcp = self::inputsById($this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, $variantId), 'inputs');

            self::assertIsArray($apiVariant['inputs'] ?? null);
            foreach ($apiVariant['inputs'] as $apiInput) {
                self::assertIsArray($apiInput);
                self::assertIsString($apiInput['id']);
                $expected = null;
                if ($apiInput['fontOptions'] !== null) {
                    self::assertIsArray($apiInput['fontOptions']);
                    $expected = [];
                    foreach ($apiInput['fontOptions'] as $option) {
                        self::assertIsArray($option);
                        $expected[] = $option['family'];
                    }
                    $seen++;
                }

                self::assertArrayHasKey($apiInput['id'], $mcp, 'Input ' . $apiInput['id']);
                self::assertArrayHasKey('fontOptions', $mcp[$apiInput['id']], 'Input ' . $apiInput['id']);
                self::assertSame($expected, $mcp[$apiInput['id']]['fontOptions'], 'Input ' . $apiInput['id']);
            }
        }

        self::assertGreaterThan(0, $seen, 'the fixtures carry inputs with a font offer');
    }

    /**
     * `richTextOptions` is a capability announcement: it appears exactly when a
     * value here may carry styling, and its fonts are the strings such a value
     * must name byte for byte.
     */
    public function testRichTextOptionsAppearOnlyWhenARichInputExists(): void
    {
        $rich = $this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertSame(
            [
                'fonts' => ['Rubik (Rubik Regular)', 'Rubik (Rubik Bold)'],
                'colors' => ['#c8102e', '#004e7c'],
            ],
            $rich['richTextOptions'],
        );

        // Same project, same fonts and colours — but no fillable rich input, so
        // no options at all.
        $plain = $this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::GROUPED_PRESET_VARIANT_ID);

        self::assertNull($plain['richTextOptions']);
    }

    /**
     * The flag a design tool must read before writing. It lives on the VARIANT:
     * the same template also holds a hand-added variant that stays editable.
     */
    public function testGroupCreatedVariantIsMarkedGrouped(): void
    {
        self::assertTrue(
            $this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::GROUPED_PRESET_VARIANT_ID)['grouped'],
        );

        self::assertFalse(
            $this->describe(
                TestDataFixture::MCP_TOKEN_ACTIVE,
                TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID,
            )['grouped'],
            'A hand-added variant on a grouped template is not group-created and stays individually editable.',
        );
    }

    /**
     * A variant nobody has drawn on: no inputs, no image slots, no containers —
     * and still a complete, usable answer rather than an error.
     */
    public function testVariantWithoutACanvasIsDescribedAsEmpty(): void
    {
        $result = $this->describe(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID,
        );

        self::assertSame([], $result['inputs']);
        self::assertSame([], $result['imageInputs']);
        self::assertSame([], $result['containers']);
        self::assertNull($result['richTextOptions']);
    }

    /**
     * The Done-when "shared" case: a user who owns nothing reaches the project
     * shared with them through the ordinary voter, and sees exactly what the
     * owner sees.
     */
    public function testSharedUserSeesTheSameDescription(): void
    {
        $shared = $this->describe(TestDataFixture::MCP_TOKEN_SHARED_USER, TestDataFixture::ORIENTATION_VARIANT_ID);
        $owner = $this->describe(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::ORIENTATION_VARIANT_ID);

        self::assertSame($owner, $shared);
    }

    /**
     * THE anti-enumeration guarantee. USER_1 cannot see PROJECT_2's variant,
     * and there is no variant at all behind the second id — both must fail with
     * the very same words, so this tool cannot be used to discover that the
     * first one exists.
     */
    public function testForeignVariantIsIndistinguishableFromAnUnknownId(): void
    {
        $foreign = $this->callDescribe(
            TestDataFixture::MCP_TOKEN_ACTIVE,
            TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID,
        );
        $unknown = $this->callDescribe(TestDataFixture::MCP_TOKEN_ACTIVE, '00000000-0000-0000-0000-0000000000ff');

        self::assertTrue($foreign['isError']);
        self::assertTrue($unknown['isError']);

        // Only the echoed id may differ.
        self::assertSame(
            str_replace(TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID, '<id>', $foreign['text']),
            str_replace('00000000-0000-0000-0000-0000000000ff', '<id>', $unknown['text']),
        );

        self::assertStringContainsString('was not found, or this account cannot access it', $foreign['text']);
    }

    /**
     * The refusal is the VOTER talking, not a hard-coded owner check: an admin
     * reaches the very variant that was just refused to USER_1.
     */
    public function testAdminReachesAVariantTheyDoNotOwn(): void
    {
        $result = $this->describe(TestDataFixture::MCP_TOKEN_ADMIN, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_2_ID);

        self::assertSame(TestDataFixture::CUSTOM_TEMPLATE_2_ID, $result['templateId']);
        self::assertSame('Project 2', $result['projectName']);
    }

    /**
     * A string that cannot be a variant id is NOT folded into the not-found
     * message: it leaks nothing, and an agent that sent a TEMPLATE id or a name
     * needs to be told so.
     */
    public function testMalformedVariantIdIsRejectedWithAnActionableMessage(): void
    {
        $result = $this->callDescribe(TestDataFixture::MCP_TOKEN_ACTIVE, 'Orientation Template');

        self::assertTrue($result['isError']);
        self::assertStringContainsString('is not a valid template variant id', $result['text']);
        self::assertStringContainsString('find_templates', $result['text']);
    }

    /**
     * The description is what makes an agent call this before filling and then
     * respect the constraints it reports, so the load-bearing sentences are
     * locked here — together with the generated schema, which is derived from
     * reflection at compile time and is the only proof the argument arrives.
     */
    public function testToolIsAdvertisedWithAnAgentFacingDescription(): void
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, TestDataFixture::MCP_TOKEN_READ_ONLY);

        TestingMcpClient::request($browser, 'tools/list', sessionId: $sessionId, token: TestDataFixture::MCP_TOKEN_READ_ONLY);

        $result = self::decode($browser->getResponse())['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['tools']);

        $tool = null;

        foreach ($result['tools'] as $candidate) {
            self::assertIsArray($candidate);

            if ($candidate['name'] === 'describe_variant') {
                $tool = $candidate;
            }
        }

        self::assertIsArray($tool, 'describe_variant is not advertised to a templates:read token.');

        $description = $tool['description'];
        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence never matches.
        self::assertStringContainsString('Describes ONE variant in full', $description);
        self::assertStringContainsString('never key by', $description);

        $schema = $tool['inputSchema'];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('variantId', $schema['properties']);
        self::assertSame(['variantId'], $schema['required']);
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * Calls `describe_variant` and returns its decoded payload, failing the
     * test if the tool reported an error.
     *
     * @return array<string, mixed>
     */
    private function describe(string $token, string $variantId): array
    {
        $result = $this->callDescribe($token, $variantId);

        self::assertFalse($result['isError'], $result['text']);

        $payload = json_decode($result['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * The raw tool outcome. A tool error is an ordinary HTTP 200 JSON-RPC
     * RESULT carrying `isError: true` — that is the MCP contract, so the model
     * can read the message and correct itself instead of seeing a protocol
     * failure.
     *
     * @return array{isError: bool, text: string}
     */
    private function callDescribe(string $token, string $variantId): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'describe_variant',
            'arguments' => ['variantId' => $variantId],
        ], $sessionId, $token);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $payload = self::decode($response);
        self::assertArrayNotHasKey('error', $payload, (string) $response->getContent());

        $result = $payload['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['content']);
        self::assertIsArray($result['content'][0]);

        $text = $result['content'][0]['text'];
        self::assertIsString($text);

        return ['isError' => $result['isError'] === true, 'text' => $text];
    }

    /**
     * Every variant of a project as the REST listing reports it, keyed by id,
     * over the SAME browser.
     *
     * Not `TestingApiAuthentication` on purpose: that helper takes an API
     * Platform `Client`, and a test may create only one client — this suite
     * needs a `KernelBrowser` for `/_mcp`. `/api/token` is an ordinary form
     * POST, so driving it directly costs nothing and keeps both surfaces in one
     * process, which is what makes the comparison meaningful.
     *
     * @return array<string, array<string, mixed>>
     */
    private function apiVariantsById(string $projectId): array
    {
        $browser = $this->browser();

        $browser->request('POST', '/api/token', [
            'grant_type' => 'client_credentials',
            'client_id' => TestDataFixture::OAUTH2_CLIENT_ID,
            'client_secret' => TestDataFixture::OAUTH2_CLIENT_SECRET,
        ], server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(Response::HTTP_OK, $browser->getResponse()->getStatusCode());

        $accessToken = self::decode($browser->getResponse())['access_token'];
        self::assertIsString($accessToken);

        $browser->request('GET', '/api/projects/' . $projectId . '/templates', server: [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $accessToken,
        ]);

        $response = $browser->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];

        foreach ($decoded as $template) {
            self::assertIsArray($template);
            self::assertIsArray($template['variants']);

            foreach ($template['variants'] as $variant) {
                self::assertIsArray($variant);
                $id = $variant['id'];
                self::assertIsString($id);

                /** @var array<string, mixed> $variant */
                $byId[$id] = $variant;
            }
        }

        return $byId;
    }

    /**
     * `[inputId => frame]` for a list of inputs, in list order — the two things
     * both surfaces MUST agree on, isolated from the fields only one of them
     * publishes.
     *
     * @return array<string, null|array<string, mixed>>
     */
    private static function idsAndFrames(mixed $inputs): array
    {
        self::assertIsArray($inputs);

        $result = [];

        foreach ($inputs as $input) {
            self::assertIsArray($input);
            $id = $input['id'];
            self::assertIsString($id);

            $frame = $input['frame'];
            self::assertTrue($frame === null || is_array($frame));

            $normalized = self::numbersAsFloats($frame);
            self::assertTrue($normalized === null || is_array($normalized));

            /** @var null|array<string, mixed> $normalized */
            $result[$id] = $normalized;
        }

        return $result;
    }

    /**
     * Every number in a decoded payload as a float.
     *
     * The two surfaces are compared as DATA, not as bytes: API Platform's
     * serializer preserves a float's zero fraction (`80.0`) while the MCP SDK
     * — which owns its own encode flags — drops it (`80`). JSON has one number
     * type, so nothing differs about the value, and a comparison that tripped
     * over the spelling would fail for a reason nobody can act on.
     */
    private static function numbersAsFloats(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        return array_map(static fn (mixed $item): mixed => self::numbersAsFloats($item), $value);
    }

    /**
     * One keyed list off a describe payload (`inputs`, `imageInputs` or
     * `containers`), preserving order.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, array<string, mixed>>
     */
    private static function inputsById(array $result, string $key): array
    {
        $rows = $result[$key];
        self::assertIsArray($rows);

        $byId = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            $id = $row['id'];
            self::assertIsString($id);

            /** @var array<string, mixed> $row */
            $byId[$id] = $row;
        }

        return $byId;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
