<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Calendar;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\Calendar;
use WBoost\Web\Repository\CalendarHistoryRepository;

/**
 * What the calendar controller tests share: reading a page's bootstrap JSON
 * (and its CSRF token), posting a save, uploading a generated picture.
 */
trait CalendarTesting
{
    /**
     * The bootstrap document of a workspace (`/calendar/{id}`) or client
     * (`/k/{token}`) page.
     *
     * @return array<string, mixed>
     */
    private static function bootstrap(KernelBrowser $browser, string $url): array
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $script = $crawler->filter('[data-controller="calendar-workspace"] script[data-calendar-workspace-target="bootstrap"]');
        self::assertCount(1, $script);

        /** @var array<string, mixed> $bootstrap */
        $bootstrap = json_decode($script->text(null, false), true, 64, JSON_THROW_ON_ERROR);

        return $bootstrap;
    }

    /**
     * @param array<string, mixed> $bootstrap
     */
    private static function csrf(array $bootstrap): string
    {
        $env = $bootstrap['env'] ?? null;
        self::assertIsArray($env);
        self::assertIsString($env['csrf']);

        return $env['csrf'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the decoded JSON answer
     */
    private static function save(KernelBrowser $browser, string $url, array $payload, string $token): array
    {
        $browser->request('POST', $url, [
            '_token' => $token,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return self::json($browser);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true);
        self::assertIsArray($decoded, (string) $browser->getResponse()->getContent());

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array<string, mixed> the upload answer
     */
    private static function upload(KernelBrowser $browser, string $url, string $token, string $kind, string $bytes, string $name): array
    {
        $file = tempnam(sys_get_temp_dir(), 'calendar-test-');
        self::assertIsString($file);
        file_put_contents($file, $bytes);

        $browser->request('POST', $url, ['_token' => $token, 'kind' => $kind], ['file' => new UploadedFile($file, $name, null, null, true)]);

        return self::json($browser);
    }

    private static function jpeg(int $width, int $height, null|string $xmpDescription = null): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, new \ImagickPixel('#7A93B8'));
        $image->setImageFormat('jpeg');

        if ($xmpDescription !== null) {
            $image->profileImage('xmp', sprintf(
                '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?><x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:description><rdf:Alt><rdf:li xml:lang="x-default">%s</rdf:li></rdf:Alt></dc:description></rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>',
                htmlspecialchars($xmpDescription, ENT_XML1),
            ));
        }

        $bytes = $image->getImageBlob();
        $image->clear();

        return $bytes;
    }

    /**
     * Walks nested decoded JSON: `dig($answer, 'layout', 'boxes', 'cal', 'x')`.
     */
    private static function dig(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value, implode('.', $path));
            self::assertArrayHasKey($key, $value, implode('.', $path));
            $value = $value[$key];
        }

        return $value;
    }

    private static function calendar(string $id): Calendar
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $calendar = $entityManager->find(Calendar::class, Uuid::fromString($id));
        self::assertInstanceOf(Calendar::class, $calendar);

        return $calendar;
    }

    /**
     * @return list<array{string, string}> [who, what], newest first
     */
    private static function history(string $calendarId): array
    {
        $entries = self::getContainer()->get(CalendarHistoryRepository::class)->latest(Uuid::fromString($calendarId), 200);

        return array_map(static fn ($entry): array => [$entry->authorKind->label(), $entry->description], $entries);
    }

    /**
     * Adds `$op` to the `content` ops of a save payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $op
     * @return array<string, mixed>
     */
    private static function withOp(array $payload, array $op): array
    {
        $content = $payload['content'] ?? [];
        self::assertIsArray($content);
        $payload['content'] = [...$content, $op];

        return $payload;
    }

    /**
     * @return array<string, mixed> a valid `events` item
     */
    private static function event(string $name, string $date = '2027-06-01'): array
    {
        return ['date' => $date, 'name' => $name, 'club' => null, 'icon' => null];
    }
}
