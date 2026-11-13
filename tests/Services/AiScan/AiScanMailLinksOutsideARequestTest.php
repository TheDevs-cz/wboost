<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services\AiScan;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use WBoost\Web\Services\AiScan\AiScanAlerts;
use WBoost\Web\Services\AiScan\AiScanNotifications;
use WBoost\Web\Services\AiScan\AiScanTime;
use WBoost\Web\Services\AiScan\AiScanUrls;
use WBoost\Web\Tests\AiScanTesting;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;

/**
 * The e-mail about an ended scan is rendered by the messenger consumer, the
 * reminder and the admins' alerts by cron commands — where no request exists.
 * Their links must start with the application's public address
 * (`DEFAULT_URI` → `framework.router.default_uri`), not with
 * `http://localhost`.
 *
 * Every mail here is rendered by a kernel that has never handled a request:
 * a kernel that has keeps the last request's host in its router.
 */
final class AiScanMailLinksOutsideARequestTest extends WebTestCase
{
    use AiScanTesting;

    private const string PUBLIC_ADDRESS = 'https://scan-links.example.test';

    /** @var array{null|string, null|string} */
    private array $previous = [null, null];

    protected function setUp(): void
    {
        $env = $_ENV['DEFAULT_URI'] ?? null;
        $server = $_SERVER['DEFAULT_URI'] ?? null;
        $this->previous = [is_string($env) ? $env : null, is_string($server) ? $server : null];
        $_ENV['DEFAULT_URI'] = self::PUBLIC_ADDRESS;
        $_SERVER['DEFAULT_URI'] = self::PUBLIC_ADDRESS;
    }

    protected function tearDown(): void
    {
        [$env, $server] = $this->previous;

        if ($env === null) {
            unset($_ENV['DEFAULT_URI']);
        } else {
            $_ENV['DEFAULT_URI'] = $env;
        }

        if ($server === null) {
            unset($_SERVER['DEFAULT_URI']);
        } else {
            $_SERVER['DEFAULT_URI'] = $server;
        }

        parent::tearDown();
    }

    public function testTheMailAboutAnEndedScanLinksToThePublicAddress(): void
    {
        $client = self::createClient();
        self::entitle(TestDataFixture::PROJECT_1_ID);
        self::switchOn();
        $scanId = self::ready($client);
        self::connection()->executeStatement("UPDATE ai_scan SET finished_at = finished_at - INTERVAL '5 minutes' WHERE id = :id", ['id' => $scanId]);

        // The consumer: a process of its own, no request ever.
        self::ensureKernelShutdown();
        self::bootKernel();

        self::assertTrue(self::getContainer()->get(AiScanNotifications::class)->sendEnded(Uuid::fromString($scanId)));

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString(
            sprintf('href="%s/project/%s/sablony/z-obrazku/%s"', self::PUBLIC_ADDRESS, TestDataFixture::PROJECT_1_ID, $scanId),
            $html,
        );
        self::assertStringNotContainsString('//localhost', $html);
    }

    public function testTheAdminsAlertAndEveryOtherLinkOfAiScanUseThePublicAddress(): void
    {
        self::bootKernel();
        self::switchOn();
        self::connection()->executeStatement(
            'UPDATE ai_scan_runtime SET scanner_seen_at = :at WHERE id = 1',
            ['at' => AiScanTime::sql(new \DateTimeImmutable('-10 minutes'))],
        );

        // What `app:ai-scan:watch` runs.
        $result = self::getContainer()->get(AiScanAlerts::class)->run();
        self::assertSame(['opened scanner_absent'], $result['lines']);

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString(self::PUBLIC_ADDRESS . '/admin/usage#ai-skeny', $html);
        self::assertStringNotContainsString('//localhost', $html);

        $urls = self::getContainer()->get(AiScanUrls::class);
        self::assertSame(self::PUBLIC_ADDRESS . '/admin/usage#ai-skeny', $urls->admin());
        self::assertStringStartsWith(self::PUBLIC_ADDRESS . '/calendar/', $urls->calendar(TestDataFixture::CALENDAR_2_ID, null));
    }
}
