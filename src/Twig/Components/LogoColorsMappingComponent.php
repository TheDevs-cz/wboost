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
use WBoost\Web\FormData\LogoColorsFormData;
use WBoost\Web\FormType\LogoColorsFormType;
use WBoost\Web\Message\Manual\EditManualLogoColorsMapping;
use WBoost\Web\Value\DefaultLogoColors;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;

#[AsLiveComponent('LogoColorsMapping')]
final class LogoColorsMappingComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentWithFormTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public null|Manual $manual = null;

    #[LiveProp]
    public string $logoVariant = '';

    #[LiveProp]
    public string $colorVariant = '';

    #[LiveProp]
    public string $defaultBackground = '';

    /** @var array<string> */
    #[LiveProp]
    public array $detectedColors = [];

    #[LiveProp]
    public bool $isSuccessful = false;

    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    /**
     * @return FormInterface<LogoColorsFormData>
     */
    protected function instantiateForm(): FormInterface
    {
        assert($this->manual !== null);

        $background = $this->manual->logoBackground($this->logoVariant, $this->colorVariant);
        $mappedColors = $this->manual->logoColorMapping($this->logoVariant, $this->colorVariant);

        $logoVariantType = LogoTypeVariant::from($this->logoVariant);
        $colors = [];

        $this->defaultBackground = DefaultLogoColors::background($logoVariantType, LogoColorVariant::from($this->colorVariant), $this->manual);

        // First fill with detected colors from the logo
        foreach ($this->manual->logo->variant($logoVariantType)->detectedColors ?? [] as $detectedColor) {
            $detectedColor = strtoupper(trim($detectedColor, '#'));
            $this->detectedColors[] = $detectedColor;
            $colors[$detectedColor] = $detectedColor;
        }

        // Override with already mapped values with custom mapping
        foreach ($mappedColors as $mapFrom => $mapTo) {
            $mapFrom = strtoupper((string) $mapFrom);

            // Might be outdated customization -> skip
            if (in_array($mapFrom, $this->detectedColors, true) === false) {
                continue;
            }

            $colors[$mapFrom] = strtoupper($mapTo);
        }

        $formData = new LogoColorsFormData($background, $colors);

        return $this->createForm(LogoColorsFormType::class, $formData);
    }

    #[LiveAction]
    public function save(): Response
    {
        $this->submitForm();
        $this->isSuccessful = $this->getForm()->isSubmitted() && $this->getForm()->isValid();

        if ($this->isSuccessful === true) {
            /** @var LogoColorsFormData $data */
            $data = $this->getForm()->getData();

            assert($this->manual !== null);

            $this->bus->dispatch(
                new EditManualLogoColorsMapping(
                    $this->manual->id,
                    LogoTypeVariant::from($this->logoVariant),
                    LogoColorVariant::from($this->colorVariant),
                    $data->background,
                    $data->colors
                ),
            );

            $this->dispatchBrowserEvent('modal:close');
        }

        return $this->redirectToRoute('manual_preview', [
            'projectSlug' => $this->manual?->project->slug,
            'manualSlug' => $this->manual?->slug,
        ]);
    }
}
