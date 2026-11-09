<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components;

use Symfony\Component\Form\FormView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Services\Manual\ManualEditForms;
use WBoost\Web\Services\Manual\ManualPreviewTurboStream;
use WBoost\Web\Value\ManualPage;

/**
 * The pencil an admin gets next to a manual page's heading — it edits that
 * page's title and description in a Bootstrap modal. A plain (not Live)
 * component since 2026-11-09: the modal's form posts to
 * `EditManualPageTextController` through Turbo, which answers with a stream
 * that updates the heading in place — the Live action used to redirect, and
 * every save threw the admin back to the top of the manual.
 */
#[AsTwigComponent('ManualPageText')]
final class ManualPageTextComponent
{
    public null|Manual $manual = null;

    /** The `ManualPage` case value the pencil belongs to. */
    public string $page = '';

    public function __construct(
        readonly private ManualEditForms $forms,
    ) {
    }

    public function manualPage(): ManualPage
    {
        return ManualPage::from($this->page);
    }

    public function form(): FormView
    {
        assert($this->manual !== null);

        return $this->forms->pageText($this->manual, $this->manualPage())->createView();
    }

    public function bodyId(): string
    {
        return ManualPreviewTurboStream::pageTextBodyId($this->manualPage());
    }
}
