<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use Doctrine\ORM\EntityManagerInterface;
use WBoost\Web\Entity\Project;
use WBoost\Web\Entity\User;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;
use WBoost\Web\Value\SharingLevel;

/**
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantInputResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantImageInputResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplateVariantImageInputFrameResponse
 * @covers \WBoost\Web\Api\SocialNetworkTemplates\SocialNetworkTemplatesProvider
 */
final class SocialNetworkTemplatesTest extends ApiTestCase
{
    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testReturnsTemplatesForRequestedProject(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json; charset=utf-8');

        $body = $response->toArray();

        $ids = [];
        foreach ($body as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('id', $row);
            self::assertIsString($row['id']);
            $ids[] = $row['id'];
        }

        self::assertContains(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID, $ids);
        self::assertNotContains(
            TestDataFixture::SOCIAL_NETWORK_TEMPLATE_2_ID,
            $ids,
            'Templates from another project must not appear.',
        );
    }

    public function testReturnsNotFoundForProjectOwnedByAnotherUser(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        // PROJECT_2 belongs to USER_2 — querying it with USER_1's token must 404,
        // not leak the project's existence or its templates.
        $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_2_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testReturnsTemplatesForProjectSharedWithApiUser(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        // Share PROJECT_2 (owned by USER_2) with USER_1: API visibility follows
        // ProjectVoter, so any share opens the listing — transferring a
        // project's ownership must not cut off integrations that keep access
        // through a share.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->find(Project::class, TestDataFixture::PROJECT_2_ID);
        $user = $entityManager->find(User::class, TestDataFixture::USER_1_ID);
        self::assertNotNull($project);
        self::assertNotNull($user);
        $project->share($user, SharingLevel::Read, new \DateTimeImmutable());
        $entityManager->flush();

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_2_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();

        $ids = [];
        foreach ($response->toArray() as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('id', $row);
            $ids[] = $row['id'];
        }

        self::assertContains(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_2_ID, $ids);
    }

    public function testReturnsNotFoundForUnknownProject(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request('GET', '/api/projects/00000000-0000-0000-0000-0000000000ff/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testEmbedsVariantsAndInputs(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $body = $response->toArray();

        $template = self::findTemplate($body, TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID);

        self::assertSame('Insta Template 1', $template['name'] ?? null);
        self::assertArrayHasKey('categoryId', $template);
        self::assertNull($template['categoryId']);
        self::assertArrayHasKey('categoryName', $template);
        self::assertNull($template['categoryName']);

        self::assertIsArray($template['variants'] ?? null);
        self::assertCount(1, $template['variants']);

        $variant = $template['variants'][0];
        self::assertIsArray($variant);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID, $variant['id'] ?? null);
        self::assertSame('1:1', $variant['dimension'] ?? null);
        self::assertSame(1080, $variant['width'] ?? null);
        self::assertSame(1080, $variant['height'] ?? null);

        self::assertIsString($variant['exportUrl'] ?? null);
        self::assertStringContainsString(
            '/api/social-network-template-variants/' . TestDataFixture::SOCIAL_NETWORK_TEMPLATE_VARIANT_1_ID . '/export',
            $variant['exportUrl'],
        );

        self::assertIsArray($variant['inputs'] ?? null);
        self::assertCount(4, $variant['inputs']);

        $headline = $variant['inputs'][0];
        self::assertIsArray($headline);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID, $headline['id'] ?? null);
        self::assertArrayNotHasKey('index', $headline, 'index field must be removed in favour of id.');
        self::assertSame('headline', $headline['name'] ?? null);
        self::assertSame(30, $headline['maxLength'] ?? null);
        self::assertFalse($headline['locked'] ?? null);
        self::assertFalse($headline['uppercase'] ?? null);
        self::assertFalse($headline['hidable'] ?? null);

        // Text frame from the i-th-Textbox positional binding (headline = 1st).
        self::assertIsArray($headline['frame'] ?? null);
        self::assertEqualsWithDelta(80.0, $headline['frame']['x'] ?? null, 0.001);
        self::assertEqualsWithDelta(60.0, $headline['frame']['y'] ?? null, 0.001);
        self::assertEqualsWithDelta(520.0, $headline['frame']['width'] ?? null, 0.001);
        self::assertEqualsWithDelta(90.0, $headline['frame']['height'] ?? null, 0.001);

        // Stacking order: one index space shared with imageInputs[].layerIndex —
        // the fixture canvas paints the two image placeholders first (0, 1),
        // then the four textboxes (2..5).
        self::assertSame(2, $headline['layerIndex'] ?? null);

        $tagline = $variant['inputs'][1];
        self::assertIsArray($tagline);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID, $tagline['id'] ?? null);
        self::assertSame('tagline', $tagline['name'] ?? null);
        self::assertArrayHasKey('maxLength', $tagline);
        self::assertNull($tagline['maxLength']);
        self::assertTrue($tagline['uppercase'] ?? null);
        self::assertFalse($tagline['hidable'] ?? null);
        self::assertSame(3, $tagline['layerIndex'] ?? null);

        $locked = $variant['inputs'][2];
        self::assertIsArray($locked);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_LOCKED_ID, $locked['id'] ?? null);
        self::assertArrayHasKey('name', $locked);
        self::assertNull($locked['name']);
        self::assertTrue($locked['locked'] ?? null);
        // A frame is exposed even for a locked input (consumers may draw a
        // read-only box); it is the 3rd textbox.
        self::assertIsArray($locked['frame'] ?? null);
        self::assertEqualsWithDelta(80.0, $locked['frame']['x'] ?? null, 0.001);
        self::assertEqualsWithDelta(300.0, $locked['frame']['y'] ?? null, 0.001);

        $badge = $variant['inputs'][3];
        self::assertIsArray($badge);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_BADGE_ID, $badge['id'] ?? null);
        self::assertSame('badge', $badge['name'] ?? null);
        self::assertTrue($badge['hidable'] ?? null);
        self::assertSame(5, $badge['layerIndex'] ?? null);
    }

    public function testEmbedsImageInputs(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $template = self::findTemplate($response->toArray(), TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID);

        self::assertIsArray($template['variants'] ?? null);
        $variant = $template['variants'][0];
        self::assertIsArray($variant);
        self::assertIsArray($variant['imageInputs'] ?? null);
        self::assertCount(2, $variant['imageInputs']);

        $photo = $variant['imageInputs'][0];
        self::assertIsArray($photo);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_PHOTO_ID, $photo['id'] ?? null);
        self::assertSame('photo', $photo['name'] ?? null);
        self::assertSame('Your photo', $photo['description'] ?? null);
        self::assertTrue($photo['allowMove'] ?? null);
        self::assertTrue($photo['allowResize'] ?? null);
        self::assertTrue($photo['allowRotate'] ?? null);
        self::assertTrue($photo['hidable'] ?? null);
        self::assertSame([TestDataFixture::FILE_DIRECTORY_ALLOWED_ID], $photo['allowedDirectoryIds'] ?? null);

        // The resolved upload/pick targets carry folder names; a restricted
        // slot never includes the gallery root.
        self::assertSame(
            [['id' => TestDataFixture::FILE_DIRECTORY_ALLOWED_ID, 'name' => 'Photos']],
            $photo['directories'] ?? null,
        );
        self::assertFalse($photo['includesRoot'] ?? null);

        // Frame derived from the placeholder object's displayed bbox.
        self::assertIsArray($photo['frame'] ?? null);
        self::assertEqualsWithDelta(100.0, $photo['frame']['x'] ?? null, 0.001);
        self::assertEqualsWithDelta(120.0, $photo['frame']['y'] ?? null, 0.001);
        self::assertEqualsWithDelta(400.0, $photo['frame']['width'] ?? null, 0.001);
        self::assertEqualsWithDelta(300.0, $photo['frame']['height'] ?? null, 0.001);

        self::assertIsString($photo['defaultImageUrl'] ?? null);
        self::assertStringContainsString('fixtures/standin-photo.png', $photo['defaultImageUrl']);

        // Stacking order shares one index space with inputs[].layerIndex: the
        // photo placeholder is the very first (backmost) canvas object.
        self::assertSame(0, $photo['layerIndex'] ?? null);

        // The locked slot exposes its restrictive flags.
        $locked = $variant['imageInputs'][1];
        self::assertIsArray($locked);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_IMAGE_LOCKED_ID, $locked['id'] ?? null);
        self::assertFalse($locked['allowMove'] ?? null);
        self::assertFalse($locked['allowResize'] ?? null);
        self::assertFalse($locked['allowRotate'] ?? null);
        self::assertFalse($locked['hidable'] ?? null);
        self::assertSame(1, $locked['layerIndex'] ?? null);
    }

    /**
     * Containers ("smart text areas") + the per-input reflow metadata: the
     * variant exposes containers[] {id, maxHeight, y, memberInputIds}, member
     * inputs carry containerId, and every located input carries textStyle so
     * a consumer can mirror the reflow client-side.
     */
    public function testEmbedsContainersAndTextStyles(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $template = self::findTemplate($response->toArray(), TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID);
        $variants = $template['variants'] ?? null;
        self::assertIsArray($variants);
        $variant = $variants[0] ?? null;
        self::assertIsArray($variant);

        // --- containers[] ---------------------------------------------------
        $containers = $variant['containers'] ?? null;
        self::assertIsArray($containers);
        self::assertCount(1, $containers);
        $container = $containers[0];
        self::assertIsArray($container);
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID, $container['id'] ?? null);
        self::assertEqualsWithDelta(200.0, $container['maxHeight'] ?? null, 0.001);
        // y = designed top of the first member (headline textbox at y=60).
        self::assertEqualsWithDelta(60.0, $container['y'] ?? null, 0.001);
        self::assertSame(
            [
                TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID,
                TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID,
            ],
            $container['memberInputIds'] ?? null,
        );

        // --- inputs[].containerId + textStyle --------------------------------
        $inputs = $variant['inputs'] ?? null;
        self::assertIsArray($inputs);
        /** @var array<string, array<string, mixed>> $inputsById */
        $inputsById = [];
        foreach ($inputs as $input) {
            self::assertIsArray($input);
            self::assertIsString($input['id'] ?? null);
            $inputsById[$input['id']] = $input;
        }

        $headline = $inputsById[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID];
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID, $headline['containerId'] ?? null);
        $headlineStyle = $headline['textStyle'] ?? null;
        self::assertIsArray($headlineStyle);
        self::assertSame('Rubik (Rubik Bold)', $headlineStyle['fontFamily'] ?? null);
        self::assertEqualsWithDelta(24.0, $headlineStyle['fontSize'] ?? null, 0.001);
        self::assertEqualsWithDelta(1.4, $headlineStyle['lineHeight'] ?? null, 0.001);
        self::assertEqualsWithDelta(0.0, $headlineStyle['charSpacing'] ?? null, 0.001);

        $tagline = $inputsById[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_TAGLINE_ID];
        self::assertSame(TestDataFixture::SOCIAL_NETWORK_VARIANT_1_CONTAINER_ID, $tagline['containerId'] ?? null);
        // No explicit style props on the fixture textbox -> Fabric defaults.
        $taglineStyle = $tagline['textStyle'] ?? null;
        self::assertIsArray($taglineStyle);
        self::assertSame('Times New Roman', $taglineStyle['fontFamily'] ?? null);
        self::assertEqualsWithDelta(40.0, $taglineStyle['fontSize'] ?? null, 0.001);

        // Independent input: no containerId.
        $locked = $inputsById[TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_LOCKED_ID];
        self::assertNull($locked['containerId'] ?? null);
    }

    /**
     * Rich-text (WYSIWYG) metadata: the headline input carries richText: true
     * and the variant exposes richTextOptions — the font whitelist (canvas
     * families expanded to ALL their faces, with grouping metadata + a font
     * URL for consumer @font-face) and the brand color swatches (primary
     * first, lowercase #rrggbb).
     */
    public function testEmbedsRichTextFlagAndOptions(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/social-network-templates', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $template = self::findTemplate($response->toArray(), TestDataFixture::SOCIAL_NETWORK_TEMPLATE_1_ID);
        $variants = $template['variants'] ?? null;
        self::assertIsArray($variants);
        $variant = $variants[0] ?? null;
        self::assertIsArray($variant);

        $inputs = $variant['inputs'] ?? null;
        self::assertIsArray($inputs);
        foreach ($inputs as $input) {
            self::assertIsArray($input);
            $id = $input['id'] ?? null;
            self::assertIsString($id);
            $expected = $id === TestDataFixture::SOCIAL_NETWORK_VARIANT_1_INPUT_HEADLINE_ID;
            self::assertSame($expected, $input['richText'] ?? null, 'richText flag mismatch for input ' . $id);
        }

        $options = $variant['richTextOptions'] ?? null;
        self::assertIsArray($options);

        $fonts = $options['fonts'] ?? null;
        self::assertIsArray($fonts);
        $families = [];
        foreach ($fonts as $font) {
            self::assertIsArray($font);
            $families[] = $font['family'] ?? null;
        }
        self::assertSame(['Rubik (Rubik Regular)', 'Rubik (Rubik Bold)'], $families);

        $bold = $fonts[1] ?? null;
        self::assertIsArray($bold);
        self::assertSame('Rubik', $bold['fontName'] ?? null);
        self::assertSame('Rubik Bold', $bold['faceName'] ?? null);
        self::assertSame(700, $bold['weight'] ?? null);
        self::assertSame('normal', $bold['style'] ?? null);
        $boldUrl = $bold['url'] ?? null;
        self::assertIsString($boldUrl);
        self::assertStringContainsString('fixtures/fonts/rubik-bold.ttf', $boldUrl);

        // Primary brand color first, secondary after, normalized lowercase.
        self::assertSame(['#c8102e', '#004e7c'], $options['colors'] ?? null);
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return array<string, mixed>
     */
    private static function findTemplate(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            self::assertIsArray($row);
            if (($row['id'] ?? null) === $id) {
                /** @var array<string, mixed> $row */
                return $row;
            }
        }

        self::fail('Template with id ' . $id . ' not found in response.');
    }
}
