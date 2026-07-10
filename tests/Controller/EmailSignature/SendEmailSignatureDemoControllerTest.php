<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\EmailSignature;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use WBoost\Web\Entity\EmailSignatureTemplate;
use WBoost\Web\Entity\EmailSignatureVariant;
use WBoost\Web\Entity\Project;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\EmailTextInput;

/**
 * @covers \WBoost\Web\Controller\EmailSignature\SendEmailSignatureTemplateDemoController
 * @covers \WBoost\Web\Controller\EmailSignature\SendEmailSignatureVariantDemoController
 * @covers \WBoost\Web\MessageHandler\EmailSignature\SendEmailSignatureDemoHandler
 */
final class SendEmailSignatureDemoControllerTest extends WebTestCase
{
    private const string INPUT_ID = 'iname';

    public function testTemplateDemoSendsEmailWithDefaultTextsToAllRecipients(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $template = $this->persistTemplate();

        $browser->request('POST', '/email-signature/' . $template->id->toString() . '/send-demo', [
            'emails' => ['first@test.cz', 'second@test.cz'],
        ]);

        $this->assertResponseRedirects();
        $this->assertEmailCount(2);

        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Ukázka e-mailového podpisu – Podpisy', $email->getSubject());
        self::assertStringContainsString('Výchozí jméno', (string) $email->getHtmlBody());
        self::assertStringContainsString('zkušební e-mail', (string) $email->getHtmlBody());
    }

    public function testVariantDemoUsesVariantValues(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $template = $this->persistTemplate();
        $variant = $this->persistVariant($template, [self::INPUT_ID => 'Jan Novák']);

        $browser->request('POST', '/email-signature-variant/' . $variant->id->toString() . '/send-demo', [
            'emails' => ['first@test.cz'],
        ]);

        $this->assertResponseRedirects();
        $this->assertEmailCount(1);

        $email = $this->getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Ukázka e-mailového podpisu – Podpisy – Obchod', $email->getSubject());
        self::assertStringContainsString('Jan Novák', (string) $email->getHtmlBody());
        self::assertStringNotContainsString('Výchozí jméno', (string) $email->getHtmlBody());
    }

    public function testInvalidAddressSendsNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $template = $this->persistTemplate();

        $browser->request('POST', '/email-signature/' . $template->id->toString() . '/send-demo', [
            'emails' => ['not-an-email'],
        ]);

        $this->assertResponseRedirects();
        $this->assertEmailCount(0);
    }

    public function testMoreThanFiveAddressesSendsNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_1_EMAIL);

        $template = $this->persistTemplate();

        $browser->request('POST', '/email-signature/' . $template->id->toString() . '/send-demo', [
            'emails' => ['a@t.cz', 'b@t.cz', 'c@t.cz', 'd@t.cz', 'e@t.cz', 'f@t.cz'],
        ]);

        $this->assertResponseRedirects();
        $this->assertEmailCount(0);
    }

    public function testForbiddenForUserWithoutProjectAccess(): void
    {
        $browser = self::createClient();
        TestingLogin::logInAsUser($browser, TestDataFixture::USER_2_EMAIL);

        $template = $this->persistTemplate();

        $browser->request('POST', '/email-signature/' . $template->id->toString() . '/send-demo', [
            'emails' => ['first@test.cz'],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertEmailCount(0);
    }

    private function persistTemplate(): EmailSignatureTemplate
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $project = $entityManager->find(Project::class, Uuid::fromString(TestDataFixture::PROJECT_1_ID));
        self::assertInstanceOf(Project::class, $project);

        $template = new EmailSignatureTemplate(
            Uuid::uuid7(),
            $project,
            new \DateTimeImmutable(),
            'Podpisy',
            '',
            null,
        );

        $template->changeCode(
            '<table><tr><td><span id="' . self::INPUT_ID . '" data-text-placeholder="">Výchozí jméno</span></td></tr></table>',
            [new EmailTextInput(self::INPUT_ID, 'Výchozí jméno')],
        );

        $entityManager->persist($template);
        $entityManager->flush();

        return $template;
    }

    /**
     * @param array<string, string> $textInputs
     */
    private function persistVariant(EmailSignatureTemplate $template, array $textInputs): EmailSignatureVariant
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $variant = new EmailSignatureVariant(
            Uuid::uuid7(),
            $template,
            new \DateTimeImmutable(),
            'Obchod',
            '',
            $textInputs,
        );

        $entityManager->persist($variant);
        $entityManager->flush();

        return $variant;
    }
}
