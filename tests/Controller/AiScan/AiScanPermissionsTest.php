<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\AiScan;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\RouterInterface;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * The permission table of architecture.md §6, route by route.
 *
 * Four roles on PROJECT_1: the **admin**, the **owner** (user 1 — the project
 * editor), a **member** it is shared with read-only, and a **stranger**
 * (user 2). Two permissions: START (admin, or an editor of an entitled
 * project while `admin_only` is off — and the mode is `on`) and USE (admin,
 * the requester, any editor of the scan's project — whatever the flags say).
 */
final class AiScanPermissionsTest extends WebTestCase
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
     * @return iterable<string, array{string, bool, bool, AiScanMode, int, null|string}>
     */
    public static function startMatrix(): iterable
    {
        foreach (array_keys(self::ROLES) as $role) {
            foreach ([true, false] as $entitled) {
                foreach ([true, false] as $adminOnly) {
                    foreach (AiScanMode::cases() as $mode) {
                        $mayStart = $role === 'admin' || ($role === 'owner' && $entitled && !$adminOnly);

                        [$status, $code] = match (true) {
                            !$mayStart => [403, 'not_enabled'],
                            $mode !== AiScanMode::On => [503, 'temporarily_unavailable'],
                            default => [201, null],
                        };

                        yield sprintf('%s · entitlement %s · admin_only %s · mode %s', $role, $entitled ? 'on' : 'off', $adminOnly ? 'on' : 'off', $mode->value)
                            => [$role, $entitled, $adminOnly, $mode, $status, $code];
                    }
                }
            }
        }
    }

    #[DataProvider('startMatrix')]
    public function testStart(string $role, bool $entitled, bool $adminOnly, AiScanMode $mode, int $status, null|string $code): void
    {
        $client = self::createClient();
        $container = $client->getContainer();
        self::entitle(self::PROJECT, $entitled);
        self::switchOn($mode, $adminOnly);
        TestingLogin::logInAsUser($client, self::ROLES[$role]);

        $answer = $this->upload($client);

        self::assertSame($status, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame($code, $answer['code'] ?? null);

        if ($status === 201) {
            self::assertIsArray($answer['scan']);
            self::assertSame('checking', $answer['scan']['status']);
        } else {
            // A refusal stores nothing: no row, no reserve.
            self::assertSame(0, self::rowCount('ai_scan'));
            self::assertSame(0, self::rowCount('ai_credit_ledger'));
        }
    }

    /**
     * "Zkusit znovu" and "Zkusit přesto" are new starts: USE of the scan AND
     * the start permission.
     *
     * @return iterable<string, array{string, string, bool, bool, AiScanMode, int, null|string}>
     */
    public static function restartMatrix(): iterable
    {
        foreach (['retry', 'continue'] as $action) {
            foreach (array_keys(self::ROLES) as $role) {
                foreach ([true, false] as $entitled) {
                    foreach ([true, false] as $adminOnly) {
                        foreach (AiScanMode::cases() as $mode) {
                            $mayUse = $role === 'admin' || $role === 'owner';
                            $mayStart = $role === 'admin' || ($role === 'owner' && $entitled && !$adminOnly);

                            [$status, $code] = match (true) {
                                !$mayUse => [403, null],
                                !$mayStart => [403, 'not_enabled'],
                                $mode !== AiScanMode::On => [503, 'temporarily_unavailable'],
                                default => [200, null],
                            };

                            yield sprintf('%s · %s · entitlement %s · admin_only %s · mode %s', $action, $role, $entitled ? 'on' : 'off', $adminOnly ? 'on' : 'off', $mode->value)
                                => [$action, $role, $entitled, $adminOnly, $mode, $status, $code];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('restartMatrix')]
    public function testRetryAndContinue(string $action, string $role, bool $entitled, bool $adminOnly, AiScanMode $mode, int $status, null|string $code): void
    {
        $client = self::createClient();
        $container = $client->getContainer();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = $action === 'retry' ? $this->failedScan($client) : $this->stoppedScan($client);

        self::entitle(self::PROJECT, $entitled);
        self::switchOn($mode, $adminOnly);
        // Whoever starts again has agreed to the consent text (its own test is below).
        self::consent(self::ROLES[$role]);
        TestingLogin::logInAsUser($client, self::ROLES[$role]);

        $answer = $this->post($client, sprintf('/ai-scan/%s/%s', $scanId, $action));

        self::assertSame($status, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertSame($code, $answer['code'] ?? null);
        self::assertSame($status === 200 ? 'queued' : ($action === 'retry' ? 'failed' : 'stopped'), self::row($scanId)['status']);
    }

    /**
     * Everything that only USES an existing scan: status, the pictures,
     * cancel, discard. It does not depend on the entitlement, on `admin_only`
     * or on the mode — so switching any of them never strands a scan.
     *
     * @return iterable<string, array{string, bool, bool, AiScanMode, bool}>
     */
    public static function useMatrix(): iterable
    {
        foreach (array_keys(self::ROLES) as $role) {
            foreach ([true, false] as $entitled) {
                foreach ([true, false] as $adminOnly) {
                    foreach (AiScanMode::cases() as $mode) {
                        yield sprintf('%s · entitlement %s · admin_only %s · mode %s', $role, $entitled ? 'on' : 'off', $adminOnly ? 'on' : 'off', $mode->value)
                            => [$role, $entitled, $adminOnly, $mode, $role === 'admin' || $role === 'owner'];
                    }
                }
            }
        }
    }

    #[DataProvider('useMatrix')]
    public function testUse(string $role, bool $entitled, bool $adminOnly, AiScanMode $mode, bool $allowed): void
    {
        $client = self::createClient();
        $container = $client->getContainer();
        self::entitle(self::PROJECT);
        self::switchOn();

        // Three scans of the owner, each ended (so that mode `off` and the
        // entitlement switch below have nothing in progress to end).
        $ready = $this->readyScan($client);
        $stopped = $this->stoppedScan($client);
        $failed = $this->failedScan($client);

        self::entitle(self::PROJECT, $entitled);
        self::switchOn($mode, $adminOnly);
        TestingLogin::logInAsUser($client, self::ROLES[$role]);

        $expected = $allowed ? 200 : 403;

        $client->request('GET', '/ai-scan/' . $ready);
        self::assertResponseStatusCodeSame($expected);

        foreach (['original', 'working', 'preview', 'candidate'] as $which) {
            $client->request('GET', sprintf('/ai-scan/%s/image/%s', $ready, $which));
            self::assertResponseStatusCodeSame($expected, $which);
        }

        // A render that is gone (a run's renders are deleted when it ends) is
        // "nothing to show", not an error — the page may have just been told of it.
        $client->request('GET', sprintf('/ai-scan/%s/image/candidate?n=3', $ready));
        self::assertResponseStatusCodeSame($allowed ? 204 : 403);

        // Cancel applies to nothing that ended: allowed callers get the
        // refusal code, the others never get that far.
        $cancel = $this->post($client, sprintf('/ai-scan/%s/cancel', $ready));
        self::assertResponseStatusCodeSame($allowed ? 409 : 403);
        self::assertSame($allowed ? 'wrong_state' : null, $cancel['code'] ?? null);

        foreach ([$ready, $stopped, $failed] as $scanId) {
            $this->post($client, sprintf('/ai-scan/%s/discard', $scanId));
            self::assertResponseStatusCodeSame($expected);
            self::assertSame($allowed ? 'discarded' : null, $allowed ? self::row($scanId)['status'] : null);
        }
    }

    public function testTheRequesterKeepsUseAfterLosingTheProject(): void
    {
        $client = self::createClient();
        $container = $client->getContainer();
        self::switchOn();
        // An admin started it in a project that is not theirs; then stops being an admin.
        $scanId = self::start(self::PROJECT, TestDataFixture::ADMIN_USER_EMAIL)->toString();
        self::connection()->executeStatement('UPDATE "user" SET roles = \'[]\' WHERE email = :email', ['email' => TestDataFixture::ADMIN_USER_EMAIL]);
        self::connection()->executeStatement('DELETE FROM project_share WHERE user_id = :id', ['id' => TestDataFixture::ADMIN_USER_ID]);
        self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();

        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('GET', '/ai-scan/' . $scanId);
        self::assertResponseIsSuccessful();

        $this->post($client, sprintf('/ai-scan/%s/cancel', $scanId));
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', self::row($scanId)['status']);

        // …but may not start again there.
        $this->post($client, sprintf('/ai-scan/%s/retry', $scanId));
        self::assertResponseStatusCodeSame(403);
    }

    public function testStartingAgainNeedsTheCallersOwnConsent(): void
    {
        $client = self::createClient();
        $container = $client->getContainer();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = $this->failedScan($client);

        // The admin never agreed: the owner's consent does not cover them.
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);
        $answer = $this->post($client, sprintf('/ai-scan/%s/retry', $scanId));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('consent_required', $answer['code'] ?? null);
        self::assertSame('failed', self::row($scanId)['status']);
    }

    public function testMyScansListsOnlyTheCallersOwn(): void
    {
        $client = self::createClient();
        $container = $client->getContainer();
        self::entitle(self::PROJECT);
        self::switchOn();

        $owners = self::start(self::PROJECT, TestDataFixture::USER_1_EMAIL)->toString();
        $admins = self::start(self::PROJECT, TestDataFixture::ADMIN_USER_EMAIL)->toString();

        foreach (['owner' => [$owners], 'admin' => [$admins], 'member' => [], 'stranger' => []] as $role => $expected) {
            TestingLogin::logInAsUser($client, self::ROLES[$role]);
            $client->request('GET', '/ai-scans/mine');

            self::assertResponseIsSuccessful();
            /** @var array{scans: list<array{id: string}>} $answer */
            $answer = json_decode((string) $client->getResponse()->getContent(), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame($expected, array_column($answer['scans'], 'id'), $role);
        }
    }

    public function testAnonymousCallersAreSentToTheLogin(): void
    {
        $client = self::createClient();
        self::entitle(self::PROJECT);
        self::switchOn();
        $scanId = self::start()->toString();

        foreach ([['GET', '/ai-scan/' . $scanId], ['GET', '/ai-scan/' . $scanId . '/image/original'], ['GET', '/ai-scans/mine'],
            ['POST', '/ai-scan/' . $scanId . '/cancel'], ['POST', '/project/' . self::PROJECT . '/ai-scan']] as [$method, $url]) {
            $client->request($method, $url);
            self::assertResponseRedirects('/login', null, $url);
        }
    }

    public function testACrossOriginPostIsRefused(): void
    {
        $client = self::createClient();
        self::switchOn();
        $scanId = self::start(self::PROJECT, TestDataFixture::ADMIN_USER_EMAIL)->toString();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        // (The test browser would add its own same-origin Referer — say what a
        // cross-site page sends instead.)
        foreach ([
            ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/page'],
            ['HTTP_REFERER' => 'https://evil.example/page'],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'http://localhost'],
        ] as $server) {
            $client->request('POST', sprintf('/ai-scan/%s/cancel', $scanId), ['_token' => 'csrf-token'], server: $server);
            self::assertResponseStatusCodeSame(403);

            $client->request('POST', '/project/' . self::PROJECT . '/ai-scan', ['_token' => 'csrf-token'], server: $server);
            self::assertResponseStatusCodeSame(403);
        }

        // A wrong token value is refused even from the same origin.
        $client->request('POST', sprintf('/ai-scan/%s/cancel', $scanId), ['_token' => 'nope'], server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);

        self::assertSame('checking', self::row($scanId)['status']);
    }

    public function testTheAdminSwitchIsAdminOnly(): void
    {
        $client = self::createClient();

        foreach (['owner', 'member', 'stranger'] as $role) {
            TestingLogin::logInAsUser($client, self::ROLES[$role]);
            $client->request('POST', sprintf('/project/%s/ai-scan/entitlement', self::PROJECT), ['enabled' => '1']);
            self::assertResponseStatusCodeSame(403, $role);
        }

        self::assertFalse(self::connection()->fetchOne('SELECT ai_scan_enabled FROM project WHERE id = :id', ['id' => self::PROJECT]));
    }

    /**
     * No scan resource in the REST API, and no scan route outside the ones
     * the permission table covers.
     */
    public function testTheRoutesAreExactlyTheOnesTheTableCovers(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);

        $scanRoutes = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();

            if (str_starts_with($path, '/api') && stripos($path, 'scan') !== false) {
                self::fail('The REST API must expose no scan resource: ' . $path);
            }

            if (preg_match('#ai-scan|/internal/#', $path) === 1) {
                $scanRoutes[$name] = implode('|', $route->getMethods()) . ' ' . $path;
            }
        }

        ksort($scanRoutes);

        // The P3 routes are walked role by role in AiScanReviewPermissionsTest.
        self::assertSame([
            // ROLE_ADMIN + CSRF — AdminAiScansTabTest.
            'admin_ai_scan_runtime' => 'POST /admin/ai-scan/runtime',
            'ai_scan_accept' => 'POST /ai-scan/{id}/accept',
            'ai_scan_access_request' => 'POST /project/{projectId}/ai-scan/access-request',
            'ai_scan_access_request_resolve' => 'POST /admin/ai-scan/access-request/{id}/{action}',
            'ai_scan_access_requests' => 'GET /admin/ai-scan/access-requests',
            'ai_scan_action' => 'POST /ai-scan/{id}/{action}',
            'ai_scan_entry' => 'GET /project/{projectId}/ai-scan/entry',
            'ai_scan_feedback' => 'POST /ai-scan/{id}/{action}',
            'ai_scan_fix' => 'POST /ai-scan/{id}/{action}',
            'ai_scan_image' => 'GET /ai-scan/{id}/image/{which}',
            'ai_scan_pictures' => 'POST /ai-scan/{id}/pictures',
            'ai_scan_proposal' => 'GET /ai-scan/{id}/proposal',
            'ai_scan_start' => 'POST /project/{projectId}/ai-scan',
            'ai_scan_status' => 'GET /ai-scan/{id}',
            'ai_scans_mine' => 'GET /ai-scans/mine',
            'ai_scans_project' => 'GET /project/{projectId}/ai-scans',
            'internal_scanner_artifact' => 'PUT /internal/scanner/{id}/artifact/{which}',
            'internal_scanner_beat' => 'POST /internal/scanner/{id}/beat',
            'internal_scanner_claim' => 'POST /internal/scanner/claim',
            'internal_scanner_faces' => 'POST /internal/scanner/{id}/faces',
            'internal_scanner_finish' => 'POST /internal/scanner/{id}/finish',
            'internal_scanner_release' => 'POST /internal/scanner/{id}/release',
            'internal_scanner_render' => 'POST /internal/scanner/{id}/render',
            'internal_scanner_source' => 'GET /internal/scanner/{id}/source',
            'internal_scanner_tick' => 'POST /internal/scanner/tick',
            'project_ai_scan_set' => 'POST /project/{projectId}/ai-scan/entitlement',
        ], $scanRoutes);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function upload(KernelBrowser $client): array
    {
        $path = self::picture();

        $client->request(
            'POST',
            sprintf('/project/%s/ai-scan', self::PROJECT),
            ['_token' => 'csrf-token', 'kind' => 'template', 'requestId' => bin2hex(random_bytes(6)), 'consent' => '1'],
            ['picture' => new UploadedFile($path, 'design.png', 'image/png', null, true)],
            ['HTTP_ORIGIN' => 'http://localhost'],
        );

        @unlink($path);

        return self::json($client);
    }

    /**
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, string $url): array
    {
        $client->request('POST', $url, ['_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);

        return self::json($client);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        /** @var array<string, mixed> $json */
        $json = is_array($decoded) ? $decoded : [];

        return $json;
    }

    private function readyScan(KernelBrowser $client): string
    {
        $build = self::running($client);
        self::scanner($client, 'POST', $build['id'] . '/render', ['run' => 1, 'seq' => 1, 'design' => self::design()], $build['lease']);
        self::scanner($client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'proposed', 'candidate' => 1, 'report' => self::report(1), 'usage' => []], $build['lease']);

        return $build['id'];
    }

    private function failedScan(KernelBrowser $client): string
    {
        $build = self::running($client);
        self::scanner($client, 'POST', $build['id'] . '/finish', ['run' => 1, 'outcome' => 'failed', 'error' => 'timeout', 'usage' => []], $build['lease']);

        return $build['id'];
    }

    private function stoppedScan(KernelBrowser $client): string
    {
        $scanId = self::start()->toString();
        $intake = self::claim($client, 'intake');
        self::scanner($client, 'POST', $scanId . '/finish', ['run' => 1, 'outcome' => 'stopped', 'reason' => 'photo_of_print', 'usage' => []], $intake['lease']);

        return $scanId;
    }
}
