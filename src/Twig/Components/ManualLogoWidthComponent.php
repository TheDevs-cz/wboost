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
use WBoost\Web\FormData\ManualLogoWidthFormData;
use WBoost\Web\FormType\ManualLogoWidthFormType;
use WBoost\Web\Message\Manual\EditManualLogoSlotWidth;

/**
 * The pencil that sizes ONE logo card. It edits the top of the width cascade
 * (see `Manual::logoDisplayWidth()`): clearing the field hands the card back
 * to the width of its logo variant, which is what the modal's placeholder
 * shows.
 */
#[AsLiveComponent('ManualLogoWidth')]
final class ManualLogoWidthComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public null|Manual $manual = null;

    /** `<page>.<logoVariant>.<colorVariant|base>` — the card's identity. */
    #[LiveProp]
    public string $slot = '';

    #[LiveProp]
    public string $logoVariant = '';

    #[LiveProp]
    public bool $isSuccessful = false;

    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    public function modalId(): string
    {
        // The slot id carries dots, which a CSS selector would read as class
        // separators — the modal is addressed by `data-bs-target`.
        return 'manualLogoWidthModal-' . str_replace('.', '-', $this->slot);
    }

    /**
     * The width this card falls back to when it carries none of its own.
     */
    public function variantWidth(): null|int
    {
        assert($this->manual !== null);

        return $this->manual->logoVariantWidth($this->logoVariant);
    }

    /**
     * @return FormInterface<ManualLogoWidthFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        assert($this->manual !== null);

        $variantWidth = $this->variantWidth();

        return $this->createForm(
            ManualLogoWidthFormType::class,
            new ManualLogoWidthFormData($this->manual->logoSlotWidth($this->slot)),
            [
                'fallback_placeholder' => $variantWidth !== null ? (string) $variantWidth : 'výchozí',
                'fallback_help' => $variantWidth !== null
                    ? sprintf(
                        'Prázdné pole = %d %% nastavených pro celou variantu loga. Hodnota zde platí jen pro tento rámeček.',
                        $variantWidth,
                    )
                    : 'Prázdné pole = výchozí velikost. Hodnota zde platí jen pro tento rámeček.',
            ],
        );
    }

    #[LiveAction]
    public function save(): Response
    {
        assert($this->manual !== null);
        $manual = $this->manual;

        $this->submitForm();
        $this->isSuccessful = $this->getForm()->isSubmitted() && $this->getForm()->isValid();

        if ($this->isSuccessful === true) {
            /** @var ManualLogoWidthFormData $data */
            $data = $this->getForm()->getData();

            $this->bus->dispatch(
                new EditManualLogoSlotWidth($manual->id, $this->slot, $data->displayWidth),
            );

            $this->dispatchBrowserEvent('modal:close');
        }

        return $this->redirectToRoute('manual_preview', [
            'projectSlug' => $manual->project->slug,
            'manualSlug' => $manual->slug,
        ]);
    }
}
