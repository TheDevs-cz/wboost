<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Api\Fonts\ProjectFontResponse
 * @covers \WBoost\Web\Api\Fonts\ProjectFontsProvider
 */
final class ProjectFontsTest extends ApiTestCase
{
    public function testRequiresAuthentication(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/fonts');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testListsEveryFaceWithFabricFamilyStringsAndUrls(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_1_ID . '/fonts', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $this->assertResponseIsSuccessful();
        $body = $response->toArray();

        // Fixture: font "Rubik" with faces "Rubik Regular" + "Rubik Bold" —
        // flattened to one row per face, family = the Fabric canvas string.
        $byFamily = [];
        foreach ($body as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['family']);
            $byFamily[$row['family']] = $row;
        }

        self::assertArrayHasKey('Rubik (Rubik Regular)', $byFamily);
        self::assertArrayHasKey('Rubik (Rubik Bold)', $byFamily);

        $regular = $byFamily['Rubik (Rubik Regular)'];
        self::assertSame('Rubik', $regular['fontName']);
        self::assertSame('Rubik Regular', $regular['faceName']);
        self::assertSame(400, $regular['weight']);
        self::assertSame('normal', $regular['style']);
        self::assertIsString($regular['url']);
        self::assertStringContainsString('fixtures/fonts/rubik-regular.ttf', $regular['url']);

        $bold = $byFamily['Rubik (Rubik Bold)'];
        self::assertSame(700, $bold['weight']);
        self::assertIsString($bold['url']);
        self::assertStringContainsString('fixtures/fonts/rubik-bold.ttf', $bold['url']);
    }

    public function testForeignProjectIsNotFound(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        // PROJECT_2 belongs to USER_2 — querying it with USER_1's token must 404,
        // indistinguishably from a project that does not exist.
        $client->request('GET', '/api/projects/' . TestDataFixture::PROJECT_2_ID . '/fonts', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/projects/00000000-0000-0000-0000-00000000dead/fonts', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testInvalidProjectIdIsBadRequest(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $client->request('GET', '/api/projects/not-a-uuid/fonts', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $this->assertResponseStatusCodeSame(400);
    }
}
