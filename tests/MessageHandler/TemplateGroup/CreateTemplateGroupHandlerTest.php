<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\MessageHandler\TemplateGroup;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use WBoost\Web\Entity\CustomTemplate;
use WBoost\Web\Entity\SocialNetworkTemplate;
use WBoost\Web\Message\TemplateGroup\CreateTemplateGroup;
use WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Value\CustomTemplateDimension;
use WBoost\Web\Value\DimensionUnit;
use WBoost\Web\Value\GroupCustomVariantSelection;
use WBoost\Web\Value\GroupSocialVariantSelection;
use WBoost\Web\Value\TemplateDimension;

/**
 * @covers \WBoost\Web\MessageHandler\TemplateGroup\CreateTemplateGroupHandler
 */
final class CreateTemplateGroupHandlerTest extends KernelTestCase
{
    private const string PNG_1X1_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function testCreatesGroupWithOneTemplatePerModule(): void
    {
        $groupId = Uuid::uuid4();

        $handler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $handler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Test Group',
            null,
            null,
            [
                new GroupSocialVariantSelection(TemplateDimension::InstagramPost, $this->pngUpload()),
                new GroupSocialVariantSelection(TemplateDimension::InstagramStory, $this->pngUpload()),
            ],
            [
                new GroupCustomVariantSelection(new CustomTemplateDimension(DimensionUnit::Mm, 210, 297), $this->pngUpload()),
            ],
        ));
        $this->em()->flush();
        $this->em()->clear();

        $group = self::getContainer()->get(TemplateGroupRepository::class)->get($groupId);
        self::assertSame('Test Group', $group->name);

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);

        $socialTemplate = $members->socialTemplate($groupId);
        self::assertInstanceOf(SocialNetworkTemplate::class, $socialTemplate);
        self::assertSame('Test Group', $socialTemplate->name);
        self::assertNotNull($socialTemplate->group);

        $socialVariants = $members->socialVariants($groupId);
        self::assertCount(2, $socialVariants);
        // Dimension case order: 1:1 before 9:16.
        self::assertSame(TemplateDimension::InstagramPost, $socialVariants[0]->dimension);
        self::assertSame(TemplateDimension::InstagramStory, $socialVariants[1]->dimension);
        self::assertSame('{}', $socialVariants[0]->canvas, 'Group variants start with an empty canvas.');
        self::assertStringStartsWith('social-networks/', $socialVariants[0]->backgroundImage);
        self::assertStringContainsString('/background-', $socialVariants[0]->backgroundImage);

        $customTemplate = $members->customTemplate($groupId);
        self::assertInstanceOf(CustomTemplate::class, $customTemplate);
        self::assertSame('Test Group', $customTemplate->name);

        $customVariants = $members->customVariants($groupId);
        self::assertCount(1, $customVariants);
        self::assertSame(2480, $customVariants[0]->dimension->width());
        self::assertStringStartsWith('custom-templates/', $customVariants[0]->backgroundImage);
    }

    public function testSingleModuleGroupSkipsTheOtherModule(): void
    {
        $groupId = Uuid::uuid4();

        $handler = self::getContainer()->get(CreateTemplateGroupHandler::class);
        $handler(new CreateTemplateGroup(
            Uuid::fromString(TestDataFixture::PROJECT_1_ID),
            $groupId,
            'Social Only',
            null,
            null,
            [new GroupSocialVariantSelection(TemplateDimension::InstagramPost, $this->pngUpload())],
            [],
        ));
        $this->em()->flush();
        $this->em()->clear();

        $members = self::getContainer()->get(GetTemplateGroupMembers::class);

        self::assertNotNull($members->socialTemplate($groupId));
        self::assertNull($members->customTemplate($groupId), 'No custom template must be created without custom dimensions.');
        self::assertCount(0, $members->customVariants($groupId));
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        self::assertIsString($tmp);

        $bytes = base64_decode(self::PNG_1X1_BASE64, true);
        self::assertIsString($bytes);
        file_put_contents($tmp, $bytes);

        // test mode (5th arg) bypasses is_uploaded_file().
        return new UploadedFile($tmp, 'background.png', 'image/png', null, true);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
