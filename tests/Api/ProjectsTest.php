<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Api;

use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingApiAuthentication;

/**
 * @covers \WBoost\Web\Api\Projects\ProjectResponse
 * @covers \WBoost\Web\Api\Projects\ProjectsProvider
 * @covers \WBoost\Web\Entity\OAuth2ClientUser
 * @covers \WBoost\Web\Services\OAuth2\IssueAccessTokenWithUserListener
 */
final class ProjectsTest extends ApiTestCase
{
    public function testProjectsRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/projects');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testTokenEndpointRejectsInvalidClientCredentials(): void
    {
        $client = self::createClient();

        $response = $client->request('POST', '/api/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'extra' => [
                'parameters' => [
                'grant_type' => 'client_credentials',
                'client_id' => TestDataFixture::OAUTH2_CLIENT_ID,
                'client_secret' => 'wrong-secret',
                ],
            ],
        ]);

        $this->assertResponseStatusCodeSame(401);
        self::assertSame('invalid_client', $response->toArray(false)['error'] ?? null);
    }

    public function testTokenEndpointRejectsInactiveClient(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'extra' => [
                'parameters' => [
                'grant_type' => 'client_credentials',
                'client_id' => TestDataFixture::OAUTH2_INACTIVE_CLIENT_ID,
                'client_secret' => TestDataFixture::OAUTH2_INACTIVE_CLIENT_SECRET,
                ],
            ],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testTokenEndpointIssuesJwtCarryingLinkedUserUuidInSubClaim(): void
    {
        $client = self::createClient();

        $response = $client->request('POST', '/api/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'extra' => [
                'parameters' => [
                'grant_type' => 'client_credentials',
                'client_id' => TestDataFixture::OAUTH2_CLIENT_ID,
                'client_secret' => TestDataFixture::OAUTH2_CLIENT_SECRET,
                ],
            ],
        ]);

        $this->assertResponseIsSuccessful();

        $payload = $response->toArray();
        self::assertIsString($payload['access_token'] ?? null);
        self::assertSame('Bearer', $payload['token_type'] ?? null);
        self::assertSame(3600, $payload['expires_in'] ?? null);

        $segments = explode('.', $payload['access_token']);
        self::assertCount(3, $segments, 'JWT must have three segments.');

        $rawJwtPayload = base64_decode(strtr($segments[1], '-_', '+/'), true);
        self::assertIsString($rawJwtPayload);
        $jwtPayload = json_decode($rawJwtPayload, true);
        self::assertIsArray($jwtPayload);
        self::assertSame(
            TestDataFixture::USER_1_ID,
            $jwtPayload['sub'] ?? null,
            'IssueAccessTokenWithUserListener should set the JWT sub claim to the linked App User UUID.',
        );
    }

    public function testProjectsReturnsOnlyCallersOwnedProjects(): void
    {
        $client = self::createClient();
        $token = TestingApiAuthentication::getAccessToken(
            $client,
            TestDataFixture::OAUTH2_CLIENT_ID,
            TestDataFixture::OAUTH2_CLIENT_SECRET,
        );

        $response = $client->request('GET', '/api/projects', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');

        $body = $response->toArray();
        self::assertArrayHasKey('member', $body);
        self::assertIsArray($body['member']);
        self::assertCount(1, $body['member'], 'Only USER_1 projects should be returned (not USER_2 projects).');

        $project = $body['member'][0];
        self::assertIsArray($project);
        self::assertSame(TestDataFixture::PROJECT_1_ID, $project['id']);
        self::assertSame('Project 1', $project['name']);
        self::assertSame('project-1', $project['slug']);
        self::assertSame(1, $project['manualsCount']);
        self::assertSame(0, $project['sharedWithCount']);
        self::assertArrayHasKey('createdAt', $project);
    }
}
