<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\EmailSignature;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\Entity\Project;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\EmailTextInput;

/**
 * A signature is HTML somebody typed: the template by the project's owner or
 * an admin, a variant's texts by anyone the project is shared with — and
 * „Varianta podpisu“ shows it to all of them. Until 2026-11-14 the preview was
 * written into the app page itself (`innerHTML`): an `onerror=` handler ran in
 * the viewer's session and a `<style>` restyled the app.
 *
 * The page now hands the HTML to a frame that is sandboxed without scripts and
 * carries a policy that allows none; nowhere in the page is it markup. The
 * behaviour in a real browser (nothing runs, the look, the copied-out code) is
 * tests/e2e/email-signature/preview-sandbox.e2e.cjs.
 *
 * @covers \WBoost\Web\Controller\EmailSignature\EditEmailSignatureVariantController
 */
final class EmailSignaturePreviewSandboxTest extends WebTestCase
{
    private const string HOSTILE_TEMPLATE = '<table><tr><td><span id="iname" data-text-placeholder="">Jméno</span>'
        . '<img src="x" onerror="window.pwned = 1"><script>window.pwned = 2</script>'
        . '<style>body { display: none }</style><a href="javascript:window.pwned = 3">web</a></td></tr></table>';

    private const string HOSTILE_VALUE = 'Jan<img src="x" onerror="window.pwned = 4">';

    public function testThePreviewIsASandboxedFrameWithoutScripts(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/edit-email-signature-variant/' . $this->persistVariant()->id->toString());
        $this->assertResponseIsSuccessful();

        $preview = $crawler->filter('[data-email-signature-export-target="preview"]');
        self::assertCount(1, $preview);
        self::assertSame('iframe', $preview->nodeName());

        $sandbox = $preview->attr('sandbox');
        self::assertNotNull($sandbox, 'the preview frame is sandboxed');
        $tokens = array_values(array_filter(explode(' ', $sandbox)));
        // allow-same-origin: the page sizes the frame to its content. Nothing else — above all no scripts.
        self::assertSame(['allow-same-origin'], $tokens);
        self::assertNull($preview->attr('src'), 'the frame gets its document from the page (srcdoc), not from an address');
    }

    public function testTheFramesDocumentGetsAPolicyThatAllowsNoScript(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/edit-email-signature-variant/' . $this->persistVariant()->id->toString());
        $this->assertResponseIsSuccessful();

        $policy = $crawler->filter('[data-controller="email-signature-export"]')->attr('data-email-signature-export-preview-policy-value');
        self::assertNotNull($policy);

        $directives = [];
        foreach (array_filter(array_map('trim', explode(';', $policy))) as $directive) {
            $parts = explode(' ', $directive);
            $directives[array_shift($parts)] = $parts;
        }

        self::assertSame(["'none'"], $directives['default-src'] ?? null, 'whatever is not named is refused: scripts, frames, objects, requests');
        self::assertSame(["'none'"], $directives['form-action'] ?? null);
        self::assertArrayNotHasKey('script-src', $directives);
        self::assertArrayNotHasKey('script-src-elem', $directives);
        self::assertArrayNotHasKey('script-src-attr', $directives);
        self::assertArrayNotHasKey('frame-src', $directives);
        self::assertArrayNotHasKey('connect-src', $directives);
        // a signature is pictures and inline styles — those it keeps
        self::assertContains('*', $directives['img-src'] ?? []);
        self::assertContains("'unsafe-inline'", $directives['style-src'] ?? []);
    }

    public function testTheAuthoredHtmlIsNowhereMarkupOfThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $crawler = $browser->request('GET', '/edit-email-signature-variant/' . $this->persistVariant()->id->toString());
        $this->assertResponseIsSuccessful();

        $html = (string) $browser->getResponse()->getContent();

        // the page's own DOM: no element came out of the signature
        self::assertCount(0, $crawler->filter('[onerror]'));
        self::assertCount(0, $crawler->filter('a[href^="javascript:"]'));
        self::assertCount(0, $crawler->filter('[data-text-placeholder]'));
        self::assertStringNotContainsString('window.pwned = 2</script>', $html);
        self::assertStringNotContainsString('<style>body { display: none }', $html);

        // …it travels as data: the template in an attribute of the controller, the text in the input's value
        self::assertSame(
            self::HOSTILE_TEMPLATE,
            $crawler->filter('[data-controller="email-signature-export"]')->attr('data-email-signature-export-source-html-value'),
        );
        self::assertSame(self::HOSTILE_VALUE, $crawler->filter('input[data-text-input-id="iname"]')->attr('value'));
    }

    private function persistVariant(): EmailSignatureVariant
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $project = $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);

        $template = new EmailSignatureTemplate(Uuid::uuid7(), $project, new \DateTimeImmutable(), 'Podpisy', '', null);
        $template->changeCode(self::HOSTILE_TEMPLATE, [new EmailTextInput('iname', 'Jméno')]);

        $variant = new EmailSignatureVariant(Uuid::uuid7(), $template, new \DateTimeImmutable(), 'Obchod', '', ['iname' => self::HOSTILE_VALUE]);

        $entityManager->persist($template);
        $entityManager->persist($variant);
        $entityManager->flush();

        return $variant;
    }
}
