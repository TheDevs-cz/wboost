<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\UserDataBag;
use WBoost\Web\Services\AiScan\AiScanSentryScrubber;
use WBoost\Web\Services\SentryBeforeSend;

/**
 * @covers \WBoost\Web\Services\SentryBeforeSend
 */
final class SentryBeforeSendTest extends TestCase
{
    /**
     * Sentry WEB-4X: a timeout raised at the worker runner's handler closure
     * happened before the app ran — the request on the event is the previous
     * one on that worker thread, so it must not be reported.
     */
    public function testTimeoutAtTheWorkerHandlerDropsTheStaleRequest(): void
    {
        $event = self::event(
            'Error: Maximum execution time of 30 seconds exceeded',
            ['/app/public/index.php', '/app/vendor/autoload_runtime.php', '/app/vendor/symfony/runtime/Runner/FrankenPhpWorkerRunner.php'],
        );

        $event = (new SentryBeforeSend(new AiScanSentryScrubber()))($event);

        self::assertSame([], $event->getRequest());
        self::assertNull($event->getUser());
        self::assertSame('unknown_before_app', $event->getTags()['request_context'] ?? null);
        self::assertSame(['frankenphp-timeout-before-app'], $event->getFingerprint());
        self::assertArrayHasKey('attribution', $event->getExtra());
    }

    /** A timeout inside the app is about its own request — left alone. */
    public function testTimeoutInsideTheAppKeepsItsRequest(): void
    {
        $event = self::event(
            'Error: Maximum execution time of 30 seconds exceeded',
            ['/app/vendor/symfony/runtime/Runner/FrankenPhpWorkerRunner.php', '/app/src/Services/Editor/TemplateVariantImageRenderer.php'],
        );

        $event = (new SentryBeforeSend(new AiScanSentryScrubber()))($event);

        self::assertSame('https://wboost.cz/template-group/x/editor', $event->getRequest()['url'] ?? null);
        self::assertNotNull($event->getUser());
        self::assertSame([], $event->getFingerprint());
    }

    /** The AI-scan scrubber still runs first. */
    public function testScanRoutesAreStillScrubbed(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'http://web/internal/scanner/claim', 'data' => ['secret' => 'x'], 'headers' => ['Authorization' => 'Bearer t']]);

        $event = (new SentryBeforeSend(new AiScanSentryScrubber()))($event);

        self::assertArrayNotHasKey('data', $event->getRequest());
        self::assertSame([], $event->getRequest()['headers'] ?? null);
    }

    /**
     * @param list<string> $files oldest frame first, like Sentry orders them
     */
    private static function event(string $message, array $files): Event
    {
        $frames = array_map(static fn (string $file): Frame => new Frame(null, $file, 51, null, $file), $files);

        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://wboost.cz/template-group/x/editor', 'method' => 'POST']);
        $event->setUser(UserDataBag::createFromUserIpAddress('10.200.0.18'));
        $event->setExceptions([new ExceptionDataBag(new \Error($message), new Stacktrace($frames))]);

        return $event;
    }
}
