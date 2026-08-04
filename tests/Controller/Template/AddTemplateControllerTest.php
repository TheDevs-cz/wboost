<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;
use WBoost\Web\Value\DimensionPreset;
use WBoost\Web\Value\DimensionUnit;

/**
 * @covers \WBoost\Web\Controller\Template\AddTemplateController
 * @covers \WBoost\Web\FormType\AddTemplateFormType
 */
final class AddTemplateControllerTest extends WebTestCase
{
    private function addUrl(): string
    {
        return '/project/' . TestDataFixture::PROJECT_1_ID . '/add-template';
    }

    public function testFormRendersTemplateAndFirstVariantSections(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->addUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="add_template_form[template][name]"]');
        self::assertSelectorExists('input[name="add_template_form[variant][preset]"]');
        self::assertSelectorExists('select[name="add_template_form[variant][unit]"]');
        // Instagram preset buttons carry the marker param; print presets don't.
        self::assertSelectorExists('button[data-template-dimension-preset-param="1:1"]');
    }

    public function testOneStepCreateWithSocialPresetLandsInVariantEditor(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->addUrl());
        $client->submitForm('Vytvořit', [
            'add_template_form[template][name]' => 'Jednokroková šablona',
            'add_template_form[variant][preset]' => '9:16',
            'add_template_form[variant][unit]' => 'px',
            'add_template_form[variant][width]' => '1080',
            'add_template_form[variant][height]' => '1920',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~^/template-variant/[0-9a-f-]{36}/editor$~', $location);

        $variantId = Uuid::fromString(explode('/', $location)[2]);
        $variant = self::getContainer()->get(EntityManagerInterface::class)
            ->find(TemplateVariant::class, $variantId);

        self::assertInstanceOf(TemplateVariant::class, $variant);
        self::assertSame('Jednokroková šablona', $variant->template->name);
        self::assertSame(DimensionPreset::InstagramStory, $variant->dimension->preset);
        self::assertSame(1080, $variant->dimension->width());
        self::assertSame(1920, $variant->dimension->height());
        self::assertSame(TestDataFixture::PROJECT_1_ID, $variant->template->project->id->toString());
    }

    public function testOneStepCreateWithPrintDimension(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $client->request('GET', $this->addUrl());
        $client->submitForm('Vytvořit', [
            'add_template_form[template][name]' => 'Tisková šablona',
            'add_template_form[variant][preset]' => '',
            'add_template_form[variant][unit]' => 'mm',
            'add_template_form[variant][width]' => '148',
            'add_template_form[variant][height]' => '210',
        ]);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $variantId = Uuid::fromString(explode('/', $location)[2]);

        $variant = self::getContainer()->get(EntityManagerInterface::class)
            ->find(TemplateVariant::class, $variantId);

        self::assertInstanceOf(TemplateVariant::class, $variant);
        self::assertNull($variant->dimension->preset);
        self::assertSame(DimensionUnit::Mm, $variant->dimension->unit);
        self::assertSame(148.0, $variant->dimension->unitWidth);
    }

    public function testInvalidNameCreatesNothing(): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, TestDataFixture::USER_1_EMAIL);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $countBefore = $entityManager->getRepository(Template::class)->count([]);

        $client->request('GET', $this->addUrl());
        $client->submitForm('Vytvořit', [
            'add_template_form[template][name]' => 'ab',
            'add_template_form[variant][unit]' => 'mm',
            'add_template_form[variant][width]' => '210',
            'add_template_form[variant][height]' => '297',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($countBefore, $entityManager->getRepository(Template::class)->count([]));
    }
}
