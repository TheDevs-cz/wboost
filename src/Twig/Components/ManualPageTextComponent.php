<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\ManualPageTextFormData;
use WBoost\Web\FormType\ManualPageTextFormType;
use WBoost\Web\Message\Manual\EditManualPageText;
use WBoost\Web\Value\ManualPage;

/**
 * The pencil an admin gets next to a manual page's heading — it edits that
 * page's title and description. Modelled on LogoColorsMapping (pencil +
 * Bootstrap modal + Live form), which is the pattern already established on
 * the logo cards of the same page.
 */
#[AsLiveComponent('ManualPageText')]
final class ManualPageTextComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public null|Manual $manual = null;

    /** The `ManualPage` case value the pencil belongs to. */
    #[LiveProp]
    public string $page = '';

    #[LiveProp]
    public bool $isSuccessful = false;

    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    public function manualPage(): ManualPage
    {
        return ManualPage::from($this->page);
    }

    /**
     * @return FormInterface<ManualPageTextFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        assert($this->manual !== null);

        $page = $this->manualPage();

        $formData = new ManualPageTextFormData(
            $this->manual->pageTextTitleOverride($page),
            $this->manual->pageDescriptionOverride($page),
        );

        return $this->createForm(ManualPageTextFormType::class, $formData, [
            // Shown as the placeholder, so clearing a field visibly falls
            // back to the wording the page ships with.
            'default_title' => $page->defaultTitle(),
            'default_description' => $page->defaultDescriptionAsPlainText(),
        ]);
    }

    #[LiveAction]
    public function save(): Response
    {
        assert($this->manual !== null);
        $manual = $this->manual;

        $this->submitForm();
        $this->isSuccessful = $this->getForm()->isSubmitted() && $this->getForm()->isValid();

        if ($this->isSuccessful === true) {
            /** @var ManualPageTextFormData $data */
            $data = $this->getForm()->getData();

            $this->bus->dispatch(
                new EditManualPageText(
                    $manual->id,
                    $this->manualPage(),
                    $data->title,
                    $data->description,
                ),
            );

            $this->dispatchBrowserEvent('modal:close');
        }

        return $this->redirectToRoute('manual_preview', [
            'projectSlug' => $manual->project->slug,
            'manualSlug' => $manual->slug,
        ]);
    }
}
