<?php

declare(strict_types=1);

namespace WBoost\Web\Tests\Controller\Template;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use WBoost\Web\Tests\DataFixtures\TestDataFixture;
use WBoost\Web\Tests\TestingLogin;

/**
 * "Složky galerie pro uživatele" in the image popover renders the gallery's
 * folder TREE: a sub-folder sits inside its parent's (collapsed) list, behind
 * a disclosure chevron — on the variant AND the group editor.
 *
 * @covers \WBoost\Web\Controller\Template\TemplateVariantEditorController
 * @covers \WBoost\Web\Controller\TemplateGroup\TemplateGroupEditorController
 */
final class EditorGalleryFolderTreeTest extends WebTestCase
{
    public function testVariantEditorNestsSubfoldersUnderTheirParent(): void
    {
        $this->assertTree('/template-variant/' . TestDataFixture::CUSTOM_TEMPLATE_VARIANT_1_ID . '/editor', TestDataFixture::USER_1_EMAIL);
    }

    public function testGroupEditorNestsSubfoldersUnderTheirParent(): void
    {
        $this->assertTree('/template-group/' . TestDataFixture::TEMPLATE_GROUP_1_ID . '/editor', TestDataFixture::ADMIN_USER_EMAIL);
    }

    private function assertTree(string $url, string $email): void
    {
        $client = self::createClient();
        TestingLogin::logInAsUser($client, $email);
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $parent = '[data-canvas-image-properties-target="folderItem"][data-directory-id="' . TestDataFixture::FILE_DIRECTORY_ALLOWED_ID . '"]';
        // "Logos" lives inside "Photos": nested in the parent's hidden child list.
        self::assertSelectorExists($parent . ' > ul#image-dir-children-' . TestDataFixture::FILE_DIRECTORY_ALLOWED_ID . '[hidden] #image-dir-' . TestDataFixture::FILE_DIRECTORY_NESTED_ID);
        self::assertSelectorExists($parent . ' > .ed-ftree__row button.ed-ftree__chev[aria-expanded="false"][aria-controls="image-dir-children-' . TestDataFixture::FILE_DIRECTORY_ALLOWED_ID . '"]');
        // A top-level folder without children has no chevron button.
        self::assertSelectorNotExists('[data-directory-id="' . TestDataFixture::FILE_DIRECTORY_OTHER_ID . '"] button.ed-ftree__chev');
        self::assertSelectorExists('.ed-ftree > .ed-ftree__list > li [data-directory-id="' . TestDataFixture::FILE_DIRECTORY_OTHER_ID . '"]');
    }
}
