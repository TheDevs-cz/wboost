<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\TemplateExportVersion;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\ExportFillValues;

/**
 * Curating the export history: naming and pinning versions from the fill
 * page (banner + dropdown) and the dedicated history page, which lists
 * EVERY version pinned-first with a digest of its fill; all of it POST +
 * CSRF, gated on the fill surface's VIEW permission, returning to a LOCAL
 * path only.
 *
 * @covers \WBoost\Web\Controller\Template\RenameTemplateExportVersionController
 * @covers \WBoost\Web\Controller\Template\PinTemplateExportVersionController
 * @covers \WBoost\Web\Controller\Template\TemplateVariantExportHistoryController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupExportHistoryController
 * @covers \WBoost\Web\MessageHandler\Template\RenameTemplateExportVersionHandler
 * @covers \WBoost\Web\MessageHandler\Template\PinTemplateExportVersionHandler
 * @covers \WBoost\Web\Services\Security\TemplateExportVersionVoter
 * @covers \WBoost\Web\Services\Template\ExportVersionRedirect
 */
final class TemplateExportVersionCurationTest extends WebTestCase
{
    public function testRenameAndPinFromTheFillPageThenTheHistoryPageListsPinnedFirst(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $older = $this->export($client, 'Verze první', new DateTimeImmutable('2026-09-01 10:00:00'));
        $newer = $this->export($client, 'Verze druhá', new DateTimeImmutable('2026-09-02 10:00:00'));

        // Loaded-version banner carries the rename form; its token is the
        // real one the page hands out.
        $crawler = $client->request('GET', $this->fillPageUrl() . '?version=' . $older->id->toString());
        self::assertResponseIsSuccessful();
        $renameForm = $crawler->filter('form[data-export-version-rename]');
        self::assertCount(1, $renameForm);
        $renameToken = $renameForm->filter('input[name="_token"]')->attr('value');
        self::assertIsString($renameToken);

        $client->request('POST', '/export-version/' . $older->id->toString() . '/rename', [
            '_token' => $renameToken,
            'name' => '  Jarní kampaň  ',
            'redirect' => $this->fillPageUrl() . '?version=' . $older->id->toString(),
        ]);
        self::assertResponseRedirects($this->fillPageUrl() . '?version=' . $older->id->toString());
        self::assertSame('Jarní kampaň', $this->reload($older)->name);

        // Pin from the dropdown row (every row carries the toggle).
        $crawler = $client->request('GET', $this->fillPageUrl());
        self::assertResponseIsSuccessful();
        $pinForms = $crawler->filter('form[data-export-version-pin]');
        self::assertCount(2, $pinForms);
        self::assertStringNotContainsString('Připnuté verze', (string) $client->getResponse()->getContent());
        $pinToken = $pinForms->first()->filter('input[name="_token"]')->attr('value');
        self::assertIsString($pinToken);

        $client->request('POST', '/export-version/' . $older->id->toString() . '/pin', [
            '_token' => $pinToken,
            'pinned' => '1',
            'redirect' => $this->fillPageUrl(),
        ]);
        self::assertResponseRedirects($this->fillPageUrl());
        self::assertTrue($this->reload($older)->isPinned());

        // The dropdown now has a pinned section, the name replaces the date.
        $crawler = $client->request('GET', $this->fillPageUrl());
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Připnuté verze', $content);
        self::assertStringContainsString('Jarní kampaň', $content);
        self::assertStringContainsString('Zobrazit vše (2)', $content);
        self::assertCount(1, $crawler->filter(sprintf('a[href="%s"]', $this->historyPageUrl())));

        // The history page: the pinned (older) version first, digest per row.
        $crawler = $client->request('GET', $this->historyPageUrl());
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tr[data-export-version]');
        self::assertCount(2, $rows);
        self::assertSame($older->id->toString(), $rows->first()->attr('data-export-version'));
        self::assertNotNull($rows->first()->attr('data-export-version-pinned'));
        self::assertNull($rows->last()->attr('data-export-version-pinned'));
        self::assertSame('Jarní kampaň', $rows->first()->filter('input[name="name"]')->attr('value'));
        self::assertStringContainsString('headline:', $rows->first()->text());
        self::assertStringContainsString('Verze první', $rows->first()->text());
        self::assertStringContainsString('Verze druhá', $rows->last()->text());
        self::assertCount(1, $rows->first()->filter(sprintf('a[href*="version=%s"]', $older->id->toString())));

        // Unpin + unname from the history page, redirect back to it.
        $pinToken = $rows->first()->filter('form[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($pinToken);
        $client->request('POST', '/export-version/' . $older->id->toString() . '/pin', [
            '_token' => $pinToken,
            'pinned' => '0',
            'redirect' => $this->historyPageUrl(),
        ]);
        self::assertResponseRedirects($this->historyPageUrl());
        self::assertFalse($this->reload($older)->isPinned());

        $client->request('POST', '/export-version/' . $older->id->toString() . '/rename', [
            '_token' => $renameToken,
            'name' => '',
            'redirect' => $this->historyPageUrl(),
        ]);
        self::assertResponseRedirects($this->historyPageUrl());
        self::assertNull($this->reload($older)->name);

        // Unpinned again: freshest first.
        $crawler = $client->request('GET', $this->historyPageUrl());
        self::assertSame($newer->id->toString(), $crawler->filter('tr[data-export-version]')->first()->attr('data-export-version'));
    }

    public function testForeignRedirectsFallBackToTheHistoryPageAndBadTokensAreRefused(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $version = $this->export($client, 'Verze');

        $crawler = $client->request('GET', $this->historyPageUrl());
        $token = $crawler->filter('form[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        foreach (['https://evil.example/phish', '//evil.example/phish', '/\\evil.example', "/ok\r\nLocation: x", ''] as $redirect) {
            $client->request('POST', '/export-version/' . $version->id->toString() . '/pin', [
                '_token' => $token,
                'pinned' => '1',
                'redirect' => $redirect,
            ]);
            self::assertResponseRedirects($this->historyPageUrl(), message: sprintf('redirect=%s', $redirect));
        }

        $client->request('POST', '/export-version/' . $version->id->toString() . '/pin', [
            '_token' => 'forged',
            'pinned' => '0',
            'redirect' => $this->historyPageUrl(),
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload($version)->isPinned());

        $client->request('POST', '/export-version/' . $version->id->toString() . '/rename', [
            '_token' => 'forged',
            'name' => 'Nope',
        ]);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload($version)->name);
    }

    public function testCurationIsGatedOnTheFillSurfacesViewPermission(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);
        $version = $this->export($client, 'Verze');
        $crawler = $client->request('GET', $this->historyPageUrl());
        $token = $crawler->filter('form[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        // A shared user of the project can curate — the history is shared.
        TestingLogin::logInAsUser($client, TestDataFixture::SHARED_USER_EMAIL);
        $client->request('GET', $this->historyPageUrl());
        self::assertResponseIsSuccessful();

        // An outsider can neither see nor touch it (the GET has no CSRF in
        // play, so it is the voter alone that answers; the POSTs are gated by
        // the same attribute before the controller's token check runs).
        TestingLogin::logInAsUser($client, TestDataFixture::USER_2_EMAIL);
        $client->request('GET', $this->historyPageUrl());
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/export-version/' . $version->id->toString() . '/pin', ['_token' => $token, 'pinned' => '1']);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/export-version/' . $version->id->toString() . '/rename', ['_token' => $token, 'name' => 'Nope']);
        self::assertResponseStatusCodeSame(403);

        $reloaded = $this->reload($version);
        self::assertFalse($reloaded->isPinned());
        self::assertNull($reloaded->name);
    }

    public function testGroupHistoryPageAndPinningAGroupVersion(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::ADMIN_USER_EMAIL);

        $client->request('POST', '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export', [
            'textValues' => [TestDataFixture::GROUP_SHARED_INPUT_ID => 'Letní kampaň'],
        ]);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var list<TemplateExportVersion> $versions */
        $versions = $entityManager->getRepository(TemplateExportVersion::class)->findBy(['group' => TestDataFixture::TEMPLATE_GROUP_1_ID]);
        self::assertCount(1, $versions);
        $version = $versions[0];

        $historyUrl = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/export-history';
        $fillUrl = '/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/fill';

        $crawler = $client->request('GET', $historyUrl);
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('tr[data-export-version]');
        self::assertCount(1, $row);
        self::assertStringContainsString('headline:', $row->text());
        self::assertStringContainsString('Letní kampaň', $row->text());
        self::assertCount(1, $row->filter(sprintf('a[href="%s?version=%s"]', $fillUrl, $version->id->toString())));

        $token = $row->filter('form[data-export-version-pin] input[name="_token"]')->attr('value');
        self::assertIsString($token);
        $client->request('POST', '/export-version/' . $version->id->toString() . '/pin', [
            '_token' => $token,
            'pinned' => '1',
            'redirect' => 'not a path',
        ]);
        // A non-path redirect lands on the GROUP history page.
        self::assertResponseRedirects($historyUrl);
        self::assertTrue($this->reload($version)->isPinned());

        $client->request('GET', $fillUrl);
        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Připnuté verze', $content);
        self::assertStringContainsString('Zobrazit vše (1)', $content);
    }

    /**
     * Exports through the real download endpoint, then stamps the version
     * with an explicit export time: two exports in one test land within the
     * same second, and the history orders by that timestamp.
     */
    private function export(KernelBrowser $client, string $headline, null|DateTimeImmutable $exportedAt = null): TemplateExportVersion
    {
        $client->request('POST', '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/download', [
            'textValues' => [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => $headline],
        ]);
        self::assertResponseIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $version = $entityManager->getRepository(TemplateExportVersion::class)->findOneBy([
            'variant' => TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID,
            'fillValuesHash' => ExportFillValues::fromVariantWebForm(
                [TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_INPUT_HEADLINE_ID => $headline],
                [],
                [],
            )->hash(),
        ]);
        self::assertInstanceOf(TemplateExportVersion::class, $version);

        if ($exportedAt !== null) {
            $version->lastExportedAt = $exportedAt;
            $entityManager->flush();
        }

        return $version;
    }

    private function reload(TemplateExportVersion $version): TemplateExportVersion
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(TemplateExportVersion::class, $version->id);
        self::assertInstanceOf(TemplateExportVersion::class, $reloaded);

        return $reloaded;
    }

    private function fillPageUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export';
    }

    private function historyPageUrl(): string
    {
        return '/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/export-history';
    }
}
