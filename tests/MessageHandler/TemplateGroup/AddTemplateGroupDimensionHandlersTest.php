<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Message\TemplateGroup\AddTemplateGroupCustomDimension;
use WBoost\Web\Message\TemplateGroup\AddTemplateGroupSocialDimension;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\MessageHandler\TemplateGroup\AddTemplateGroupCustomDimensionHandler;
use WBoost\Web\MessageHandler\TemplateGroup\AddTemplateGroupSocialDimensionHandler;
use WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\CustomTemplateDimension;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\GroupSocialVariantSelection;
use WBoost\Web\Value\TemplateDimension;

/**
 * @covers \WBoost\Web\MessageHandler\TemplateGroup\AddTemplateGroupSocialDimensionHandler
 * @covers \WBoost\Web\MessageHandler\TemplateGroup\AddTemplateGroupCustomDimensionHandler
 */
final class AddTemplateGroupDimensionHandlersTest extends KernelTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testAppendsVariantToExistingModuleTemplate(): void
    {
        $groupId = Uuid::fromString(TestDataFixture::TEMPLATE_GROUP_1_ID);
        $variantId = Uuid::uuid4();

        $handler = self::getContainer()->get(AddTemplateGroupSocialDimensionHandler::class);
        $handler(new AddTemplateGroupSocialDimension(
            $groupId,
            $variantId,
            TemplateDimension::InstagramPortrait,
            $this->pngUpload(),
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);
        $variants = $members->socialVariants($groupId);

        self::assertCount(2, $variants, 'Group gains a member (the manually-added ungrouped variant does not count).');

        $added = null;
        foreach ($variants as $variant) {
            if ($variant->id->equals($variantId)) {
                $added = $variant;
            }
        }

        self::assertNotNull($added);
        self::assertSame(TemplateDimension::InstagramPortrait, $added->dimension);
        self::assertSame(TestDataFixture::GROUPED_SOCIAL_TEMPLATE_ID, $added->template->id->toString(), 'Variant lands on the group\'s existing module template.');
        self::assertSame('{}', $added->canvas);
    }

    public function testCreatesModuleTemplateLazilyWhenGroupLacksIt(): void
    {
        // A social-only group…
        $groupId = Uuid::uuid4();
        $createHandler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $createHandler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Lazy Custom',
            null,
            null,
            [new GroupSocialVariantSelection(TemplateDimension::InstagramPost, $this->pngUpload())],
            [],
        ));
        $this->em()->flush();

        // …receives its first custom dimension: the module template appears.
        $variantId = Uuid::uuid4();
        $handler = self::getContainer()->get(AddTemplateGroupCustomDimensionHandler::class);
        $handler(new AddTemplateGroupCustomDimension(
            $groupId,
            $variantId,
            new CustomTemplateDimension(DimensionUnit::Mm, 148, 210),
            $this->pngUpload(),
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);

        $customTemplate = $members->customTemplate($groupId);
        self::assertNotNull($customTemplate, 'The custom module template must be created lazily.');
        self::assertSame('Lazy Custom', $customTemplate->name);
        self::assertNotNull($customTemplate->group);

        $variants = $members->customVariants($groupId);
        self::assertCount(1, $variants);
        self::assertTrue($variants[0]->id->equals($variantId));
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        self::assertIsString($tmp);

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, 'background.png', 'image/png', null, true);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
