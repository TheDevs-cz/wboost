<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * The permission table of architecture.md §6 for the entry points of P3,
 * route by route: four roles × entitlement on / off × `admin_only` × the
 * three operating modes.
 *
 * Everything here only USES an existing scan (or reads a project's scans),
 * so the answer depends on WHO asks and on nothing else: the review, the
 * pictures, accept and the lists keep working after the project was switched
 * off, under `admin_only` and in every mode — a proposal is never stranded.
 */
final class AiScanReviewPermissionsTest extends WebTestCase
{
    use AiScanTesting;

    private const string PROJECT = TestDataFixture::PROJECT_1_ID;

    private const array ROLES = [
        'admin' => TestDataFixture::ADMIN_USER_EMAIL,
        'owner' => TestDataFixture::USER_1_EMAIL,
        'member' => TestDataFixture::SHARED_USER_EMAIL,
        'stranger' => TestDataFixture::USER_2_EMAIL,
    ];

    /**
     * @return iterable<string, array{string, bool, bool, AiScanMode}>
     */
    public static function matrix(): iterable
    {
        foreach (array_keys(self::ROLES) as $role) {
            foreach ([true, false] as $entitled) {
                foreach ([true, false] as $adminOnly) {
                    foreach (AiScanMode::cases() as $mode) {
                        yield sprintf('%s · entitlement %s · admin_only %s · mode %s', $role, $entitled ? 'on' : 'off', $adminOnly ? 'on' : 'off', $mode->value)
                            => [$role, $entitled, $adminOnly, $mode];
                    }
                }
            }
        }
    }

    #[DataProvider('matrix')]
    public function testEveryReviewEntryPoint(string $role, bool $entitled, bool $adminOnly, AiScanMode $mode): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = self::ready($client);

        // Mode `off` would fail a scan in progress — a ready proposal stays.
        self::entitle(self::PROJECT, $entitled);
        self::switchOn($mode, $adminOnly);
        self::assertSame('ready', self::text($scanId, 'status'));

        TestingLogin::logInAsUser($client, self::ROLES[$role]);
        $allowed = in_array($role, ['admin', 'owner'], true);
        $expected = $allowed ? 200 : 403;

        $client->request('GET', sprintf('/ai-scan/%s/proposal', $scanId));
        self::assertResponseStatusCodeSame($expected, 'proposal');

        $client->request('GET', sprintf('/ai-scan/%s/image/proposal', $scanId));
        self::assertResponseStatusCodeSame($expected, 'image');

        $client->request('GET', sprintf('/project/%s/ai-scans', self::PROJECT));
        self::assertResponseStatusCodeSame($expected, 'history');

        $client->request('GET', sprintf('/project/%s/ai-scans?view=strip', self::PROJECT));
        self::assertResponseStatusCodeSame($expected, 'strip');

        $client->request('GET', sprintf('/project/%s/ai-scan/entry', self::PROJECT));
        self::assertResponseStatusCodeSame($expected, 'entry');

        $this->post($client, sprintf('/ai-scan/%s/fix', $scanId), ['fix' => '{"type":"keep_font","group":"headline"}']);
        self::assertResponseStatusCodeSame($expected, 'fix');

        $this->post($client, sprintf('/ai-scan/%s/preview', $scanId));
        self::assertResponseStatusCodeSame($expected, 'preview');

        $picture = self::picture(300, 200);
        $client->request('POST', sprintf('/ai-scan/%s/pictures', $scanId), ['_token' => 'csrf-token', 'place' => 'foto'], ['pictures' => [new UploadedFile($picture, 'p.png', 'image/png', null, true)]], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame($expected, 'pictures');
        @unlink($picture);

        $this->post($client, sprintf('/ai-scan/%s/rating', $scanId), ['rating' => '1']);
        self::assertResponseStatusCodeSame($expected, 'rating');

        $this->post($client, sprintf('/ai-scan/%s/seen', $scanId));
        self::assertResponseStatusCodeSame($expected, 'seen');

        // The admin's request list and resolution are the admin's alone.
        $client->request('GET', '/admin/ai-scan/access-requests');
        self::assertResponseStatusCodeSame($role === 'admin' ? 200 : 403, 'access requests');
        $this->post($client, '/admin/ai-scan/access-request/00000000-0000-0000-0000-00000000dead/approve');
        self::assertResponseStatusCodeSame($role === 'admin' ? 409 : 403, 'resolve');

        // Accept last: it ends the review.
        $this->post($client, sprintf('/ai-scan/%s/accept', $scanId));
        self::assertResponseStatusCodeSame($expected, 'accept');
        self::assertSame($allowed ? 'accepted' : 'ready', self::text($scanId, 'status'));
        self::assertSame($allowed ? 1 : 0, self::rowCount('template', sprintf("id IN (SELECT result_template_id FROM ai_scan WHERE id = '%s')", $scanId)));

        // Nobody but a user who may use the scan changed anything.
        if (!$allowed) {
            self::assertSame('', self::text($scanId, 'fixes'));
            self::assertSame('', self::text($scanId, 'rating'));
        }
    }

    /**
     * "Požádat o přístup" — a project editor (architecture.md §6): the owner
     * of a project without AI scan. An admin has nothing to ask for; a
     * read-only member and a stranger may not ask.
     *
     * @return iterable<string, array{string, bool, bool, AiScanMode, int, null|string}>
     */
    public static function requestMatrix(): iterable
    {
        foreach (array_keys(self::ROLES) as $role) {
            foreach ([true, false] as $entitled) {
                foreach ([true, false] as $adminOnly) {
                    foreach (AiScanMode::cases() as $mode) {
                        [$status, $code] = match (true) {
                            $role === 'member', $role === 'stranger' => [403, null],
                            // Already able to start scans here (the mode is not part of that).
                            $role === 'admin', $entitled && !$adminOnly => [409, 'wrong_state'],
                            default => [201, null],
                        };

                        yield sprintf('%s · entitlement %s · admin_only %s · mode %s', $role, $entitled ? 'on' : 'off', $adminOnly ? 'on' : 'off', $mode->value)
                            => [$role, $entitled, $adminOnly, $mode, $status, $code];
                    }
                }
            }
        }
    }

    #[DataProvider('requestMatrix')]
    public function testAccessRequest(string $role, bool $entitled, bool $adminOnly, AiScanMode $mode, int $status, null|string $code): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT, $entitled);
        self::switchOn($mode, $adminOnly);
        TestingLogin::logInAsUser($client, self::ROLES[$role]);

        $answer = $this->post($client, sprintf('/project/%s/ai-scan/access-request', self::PROJECT), ['origin' => 'templates', 'message' => 'Chceme převést letáky.']);

        self::assertSame($status, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame($code, $answer['code'] ?? null);
        self::assertSame($status === 201 ? 1 : 0, self::rowCount('ai_access_request'));
    }

    public function testEveryNewPostNeedsTheCsrfToken(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = self::ready($client);
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        foreach (['fix', 'preview', 'pictures', 'accept', 'rating', 'seen'] as $action) {
            $client->request('POST', sprintf('/ai-scan/%s/%s', $scanId, $action), ['_token' => 'nope'], server: ['HTTP_ORIGIN' => 'http://localhost']);
            self::assertResponseStatusCodeSame(403, $action);
        }

        $client->request('POST', sprintf('/project/%s/ai-scan/access-request', self::PROJECT), ['_token' => 'nope'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('ready', self::text($scanId, 'status'));
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, string $url, array $fields = []): array
    {
        $client->request('POST', $url, ['_token' => 'csrf-token'] + $fields, server: ['HTTP_ORIGIN' => 'http://localhost']);
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        /** @var array<string, mixed> $json */
        $json = is_array($decoded) ? $decoded : [];

        return $json;
    }
}
