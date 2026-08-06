<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Mcp\Tool;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use WBoost\Web\Services\UploaderHelper;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\Mcp\TestingMcpClient;

/**
 * `find_templates` (S2-T2) — driven end to end over `/_mcp`, never as a bare
 * service call. The SDK derives a tool's input schema from reflection at
 * COMPILE time, so a tool can be perfectly correct in isolation and still fail
 * to register; only a real `tools/call` proves it is reachable.
 *
 * ## The refusal is the interesting half
 *
 * A project the caller may not see and a project id that matches nothing must
 * be INDISTINGUISHABLE — otherwise any token becomes a project-enumeration
 * oracle. {@see testForeignProjectIsIndistinguishableFromAnUnknownId()} asserts
 * the two messages are equal rather than asserting each separately, because
 * "both are errors" is exactly the weaker property that would still pass while
 * one of them leaked.
 */
final class FindTemplatesTest extends WebTestCase
{
    /**
     * One browser per test method, created on first use. `createClient()` may
     * only be called once per test (it boots the kernel), and several cases
     * here make two calls — two tokens, or two different queries.
     */
    private null|KernelBrowser $browser = null;

    /**
     * The owner's whole library: four templates, echoed alongside the project
     * they belong to.
     */
    public function testOwnerSeesEveryTemplateOfTheProject(): void
    {
        $result = $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID);

        self::assertSame(TestDataFixture::PROJECT_1_ID, $result['projectId']);
        self::assertSame('Project 1', $result['projectName']);
        self::assertNull($result['query']);

        self::assertSame(
            [
                // position 0, by name: "Custom Template 1" before "Insta
                // Template 1" before "Orientation Template"; the grouped one
                // sits at position 1.
                TestDataFixture::CUSTOM_TEMPLATE_1_ID,
                TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID,
                TestDataFixture::ORIENTATION_TEMPLATE_ID,
                TestDataFixture::GROUPED_TEMPLATE_ID,
            ],
            array_keys(self::templatesById($result)),
            'Templates come back in a stable order: position, then name, then id.',
        );
    }

    /**
     * The Done-when "shared" case: a user who owns nothing still reaches the
     * project shared with them, through the ordinary `ProjectVoter::VIEW` rule —
     * and sees the same library the owner does.
     */
    public function testSharedUserSeesTheSharedProjectsTemplates(): void
    {
        $shared = $this->findTemplates(TestDataFixture::MCP_TOKEN_SHARED_USER, TestDataFixture::PROJECT_1_ID);
        $owner = $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID);

        self::assertSame($owner, $shared);
    }

    /**
     * THE anti-enumeration guarantee. USER_1 cannot see PROJECT_2, and there is
     * no project at all behind the second id — both must fail with the very
     * same words, so a caller cannot use this tool to discover that PROJECT_2
     * exists.
     */
    public function testForeignProjectIsIndistinguishableFromAnUnknownId(): void
    {
        $foreign = $this->callFindTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_2_ID);
        $unknown = $this->callFindTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, '00000000-0000-0000-0000-0000000000ff');

        self::assertTrue($foreign['isError']);
        self::assertTrue($unknown['isError']);

        // Only the echoed id may differ — everything else about the two
        // messages has to be identical.
        self::assertSame(
            str_replace(TestDataFixture::PROJECT_2_ID, '<id>', $foreign['text']),
            str_replace('00000000-0000-0000-0000-0000000000ff', '<id>', $unknown['text']),
        );

        self::assertStringContainsString('was not found, or this account cannot access it', $foreign['text']);
    }

    /**
     * The other side of that coin: the refusal is the VOTER talking, not a
     * hard-coded owner check. An admin reaches PROJECT_2 through the same code
     * path that just refused USER_1.
     */
    public function testAdminReachesAProjectTheyDoNotOwn(): void
    {
        $result = $this->findTemplates(TestDataFixture::MCP_TOKEN_ADMIN, TestDataFixture::PROJECT_2_ID);

        self::assertSame('Project 2', $result['projectName']);
        self::assertSame(
            [TestDataFixture::CUSTOM_TEMPLATE_2_ID, TestDataFixture::SOCIAL_NETWORK_TEMPLATE_2_ID],
            array_keys(self::templatesById($result)),
        );
    }

    /**
     * A string that cannot be a project id is NOT folded into the not-found
     * message: it reveals nothing about which projects exist, and an agent that
     * sent a project NAME needs to be told so.
     */
    public function testMalformedProjectIdIsRejectedWithAnActionableMessage(): void
    {
        $result = $this->callFindTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, 'Project 1');

        self::assertTrue($result['isError']);
        self::assertStringContainsString('is not a valid project id', $result['text']);
        self::assertStringContainsString('get_context', $result['text']);
    }

    /**
     * The Done-when "grouped" case. A synchronized template carries its group,
     * and — the part that actually matters to a design tool — the flag is
     * carried PER VARIANT: the same template holds two group-created variants
     * and one that was added by hand, and only the first two are off-limits.
     */
    public function testGroupedTemplateAndItsGroupCreatedVariantsAreMarked(): void
    {
        $template = self::templatesById(
            $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID),
        )[TestDataFixture::GROUPED_TEMPLATE_ID];

        self::assertTrue($template['grouped']);
        self::assertSame(TestDataFixture::TEMPLATE_GROUP_1_ID, $template['groupId']);
        self::assertSame('Group Campaign', $template['groupName']);

        $variants = self::variantsById($template);

        self::assertTrue($variants[TestDataFixture::GROUPED_PRESET_VARIANT_ID]['grouped']);
        self::assertTrue($variants[TestDataFixture::GROUPED_FREEFORM_VARIANT_ID]['grouped']);
        self::assertFalse(
            $variants[TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID]['grouped'],
            'A hand-added variant on a grouped template is NOT group-created and stays individually editable.',
        );
    }

    public function testUngroupedTemplateCarriesNoGroup(): void
    {
        $template = self::templatesById(
            $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID),
        )[TestDataFixture::CUSTOM_TEMPLATE_1_ID];

        self::assertFalse($template['grouped']);
        self::assertNull($template['groupId']);
        self::assertNull($template['groupName']);
        self::assertFalse(self::variantsById($template)[TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID]['grouped']);
    }

    /**
     * Every per-variant field, on a free-form (A4 mm) variant: the authored
     * size and the 300-DPI canvas pixels are BOTH reported, and the field names
     * are the ones `get_context` already used for project dimensions.
     */
    public function testFreeFormVariantSummary(): void
    {
        $variant = $this->variant(TestDataFixture::CUSTOM_TEMPLATE_1_ID, TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID);

        // Whole `unit*` sizes arrive as JSON integers — PHP drops a float's
        // zero fraction and the SDK owns the encode flags.
        self::assertSame(
            ['label' => '210 × 297 mm', 'preset' => null, 'unit' => 'mm', 'unitWidth' => 210, 'unitHeight' => 297, 'width' => 2480, 'height' => 3508],
            $variant['dimension'],
        );

        self::assertSame(4, $variant['inputCount']);
        self::assertSame(
            self::getContainer()->get(UploaderHelper::class)->getPublicPath('fixtures/custom-template-bg-1.png'),
            $variant['thumbnailUrl'],
            'No preview has been rendered, so the background image stands in — the same fallback the web listing shows.',
        );
    }

    /**
     * The preset half of the same shape: `preset` is non-null exactly for the
     * fixed social formats, and it is what says a variant can be published to
     * Facebook/Instagram.
     */
    public function testPresetVariantSummary(): void
    {
        $variant = $this->variant(
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID,
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID,
        );

        self::assertSame(
            ['label' => '1:1', 'preset' => '1:1', 'unit' => 'px', 'unitWidth' => 1080, 'unitHeight' => 1080, 'width' => 1080, 'height' => 1080],
            $variant['dimension'],
        );
        self::assertSame(4, $variant['inputCount']);
    }

    /**
     * A variant nobody has drawn on yet: zero inputs, and no preview and no
     * background would mean no thumbnail — this one has a background, so the
     * zero being asserted is the input count, deliberately.
     */
    public function testVariantWithoutACanvasReportsNoInputs(): void
    {
        $variant = $this->variant(
            TestDataFixture::GROUPED_TEMPLATE_ID,
            TestDataFixture::UNGROUPED_VARIANT_ON_GROUPED_TEMPLATE_ID,
        );

        self::assertSame(0, $variant['inputCount']);
    }

    /**
     * `inputCount` and the REST API's `inputs[]` are two views of one array and
     * must never disagree — an agent told "4 inputs" here and handed 5 ids by
     * the API has no way to tell which surface lied. Both surfaces are driven
     * for real, in one process, against the same fixture project.
     */
    public function testInputCountAgreesWithTheRestApiListing(): void
    {
        $mcp = $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID);

        /** @var array<string, int> $expected */
        $expected = [];

        foreach ($this->apiTemplates(TestDataFixture::PROJECT_1_ID) as $template) {
            self::assertIsArray($template);
            self::assertIsArray($template['variants']);

            foreach ($template['variants'] as $variant) {
                self::assertIsArray($variant);
                self::assertIsString($variant['id']);
                self::assertIsArray($variant['inputs']);

                $expected[$variant['id']] = count($variant['inputs']);
            }
        }

        /** @var array<string, int> $actual */
        $actual = [];

        foreach (self::templatesById($mcp) as $template) {
            foreach (self::variantsById($template) as $variantId => $variant) {
                $actual[$variantId] = $variant['inputCount'];
            }
        }

        ksort($expected);
        ksort($actual);

        self::assertNotSame([], $expected, 'The API listing returned no variants — the comparison would be vacuous.');
        self::assertSame($expected, $actual);
    }

    /**
     * The filter really narrows, and case is irrelevant: "insta" and "INSTA"
     * both find exactly the one template whose name contains it.
     */
    public function testQueryFiltersByNameCaseInsensitively(): void
    {
        $lower = $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, 'insta');

        self::assertSame([TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID], array_keys(self::templatesById($lower)));
        self::assertSame('insta', $lower['query']);

        $upper = $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, 'INSTA');

        self::assertSame([TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID], array_keys(self::templatesById($upper)));
    }

    /**
     * The category is searched too. The fixture category's name shares no word
     * with any template name, so a hit can only have come from the category.
     */
    public function testQueryMatchesTheCategoryName(): void
    {
        $result = $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, 'PRINT MAT');

        $templates = self::templatesById($result);

        self::assertSame([TestDataFixture::CUSTOM_TEMPLATE_1_ID], array_keys($templates));
        self::assertSame(TestDataFixture::TEMPLATE_CATEGORY_1_ID, $templates[TestDataFixture::CUSTOM_TEMPLATE_1_ID]['categoryId']);
        self::assertSame(TestDataFixture::TEMPLATE_CATEGORY_1_NAME, $templates[TestDataFixture::CUSTOM_TEMPLATE_1_ID]['categoryName']);
    }

    /**
     * A query that matches nothing is an empty list, not an error — and the
     * echoed `query` is what tells the agent the emptiness came from the
     * filter rather than from an empty project.
     */
    public function testQueryMatchingNothingReturnsAnEmptyList(): void
    {
        $result = $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID, 'no such template');

        self::assertSame([], $result['templates']);
        self::assertSame('no such template', $result['query']);
    }

    /**
     * The description is what makes an agent reach for this tool at the right
     * moment and then read the `grouped` flag instead of trying to write to a
     * synchronized variant, so those two sentences are locked here.
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

            if ($candidate['name'] === 'find_templates') {
                $tool = $candidate;
            }
        }

        self::assertIsArray($tool, 'find_templates is not advertised to a templates:read token.');

        $description = $tool['description'];
        self::assertIsString($description);
        // Substrings must not span a line break — the description is the
        // reflowed `__invoke()` docblock, so a wrapped sentence never matches.
        self::assertStringContainsString('Lists the templates of one project', $description);
        self::assertStringContainsString('design tools refuse to write to it', $description);

        // Both arguments must reach the generated schema, and only one of them
        // may be required: a schema that demanded `query` would make the
        // unfiltered listing unreachable.
        $schema = $tool['inputSchema'];
        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('projectId', $schema['properties']);
        self::assertArrayHasKey('query', $schema['properties']);
        self::assertSame(['projectId'], $schema['required']);
    }

    private function browser(): KernelBrowser
    {
        return $this->browser ??= self::createClient();
    }

    /**
     * Calls `find_templates` and returns its decoded payload, failing the test
     * if the tool reported an error.
     *
     * @return array<string, mixed>
     */
    private function findTemplates(string $token, string $projectId, null|string $query = null): array
    {
        $result = $this->callFindTemplates($token, $projectId, $query);

        self::assertFalse($result['isError'], $result['text']);

        $payload = json_decode($result['text'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * The raw tool outcome: whether it reported an error, and its single text
     * content. A tool error is an ordinary HTTP 200 JSON-RPC RESULT carrying
     * `isError: true` — that is the MCP contract, so the model can read the
     * message and correct itself instead of seeing a protocol failure.
     *
     * @return array{isError: bool, text: string}
     */
    private function callFindTemplates(string $token, string $projectId, null|string $query = null): array
    {
        $browser = $this->browser();
        $sessionId = TestingMcpClient::connect($browser, $token);

        $arguments = ['projectId' => $projectId];

        if ($query !== null) {
            $arguments['query'] = $query;
        }

        TestingMcpClient::request($browser, 'tools/call', [
            'name' => 'find_templates',
            'arguments' => $arguments,
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
     * One variant of one template, for the field-level assertions.
     *
     * @return array<string, mixed>
     */
    private function variant(string $templateId, string $variantId): array
    {
        $templates = self::templatesById(
            $this->findTemplates(TestDataFixture::MCP_TOKEN_ACTIVE, TestDataFixture::PROJECT_1_ID),
        );

        return self::variantsById($templates[$templateId])[$variantId];
    }

    /**
     * The REST templates listing for a project, over the SAME browser.
     *
     * Not `TestingApiAuthentication` on purpose: that helper takes an API
     * Platform `Client`, and a test may create only one client — this suite
     * needs a `KernelBrowser` for `/_mcp`. `/api/token` is an ordinary
     * form POST, so driving it directly costs nothing and keeps both surfaces
     * in one process.
     *
     * @return list<mixed>
     */
    private function apiTemplates(string $projectId): array
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

        /** @var list<mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, array<string, mixed>>
     */
    private static function templatesById(array $result): array
    {
        $templates = $result['templates'];
        self::assertIsArray($templates);

        $byId = [];

        foreach ($templates as $template) {
            self::assertIsArray($template);
            $id = $template['id'];
            self::assertIsString($id);

            /** @var array<string, mixed> $template */
            $byId[$id] = $template;
        }

        return $byId;
    }

    /**
     * @param array<string, mixed> $template
     *
     * @return array<string, array<string, mixed>>
     */
    private static function variantsById(array $template): array
    {
        $variants = $template['variants'];
        self::assertIsArray($variants);

        $byId = [];

        foreach ($variants as $variant) {
            self::assertIsArray($variant);
            $id = $variant['id'];
            self::assertIsString($id);

            /** @var array<string, mixed> $variant */
            $byId[$id] = $variant;
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
