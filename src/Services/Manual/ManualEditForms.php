<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Manual;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use WBoost\Web\Entity\Manual;
use WBoost\Web\FormData\LogoColorsEditForm;
use WBoost\Web\FormData\LogoColorsFormData;
use WBoost\Web\FormData\ManualPageTextFormData;
use WBoost\Web\FormType\LogoColorsFormType;
use WBoost\Web\FormType\ManualPageTextFormType;
use WBoost\Web\Value\DefaultLogoColors;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;
use WBoost\Web\Value\ManualPage;

/**
 * The two pencil-modal forms of the manual preview (page texts, logo colour
 * mapping). Built in ONE place because two callers need the very same form:
 * the Twig component that renders the modal and the controller that handles
 * its POST (and re-renders the modal body with errors, or fresh after a save).
 *
 * Every form is NAMED per page / per logo × colour variant — the preview
 * renders dozens of these modals at once and a shared name would give every
 * one of them the same field ids (labels pointing at the wrong modal).
 */
final readonly class ManualEditForms
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return FormInterface<ManualPageTextFormData>
     */
    public function pageText(Manual $manual, ManualPage $page): FormInterface
    {
        return $this->formFactory->createNamed(
            'manual_page_text_' . $page->value,
            ManualPageTextFormType::class,
            new ManualPageTextFormData(
                $manual->pageTextTitleOverride($page),
                $manual->pageDescriptionOverride($page),
            ),
            [
                'action' => $this->urlGenerator->generate('manual_page_text_edit', [
                    'manualId' => $manual->id,
                    'page' => $page->value,
                ]),
                // Shown as the placeholder, so clearing a field visibly falls
                // back to the wording the page ships with.
                'default_title' => $page->defaultTitle(),
                'default_description' => $page->defaultDescriptionAsPlainText(),
            ],
        );
    }

    public function logoColors(Manual $manual, LogoTypeVariant $logoVariant, LogoColorVariant $colorVariant): LogoColorsEditForm
    {
        $background = $manual->logoBackground($logoVariant->value, $colorVariant->value);
        $mappedColors = $manual->logoColorTargets($logoVariant->value, $colorVariant->value);
        $mappedOpacities = $manual->logoColorOpacities($logoVariant->value, $colorVariant->value);

        $detectedColors = [];
        $colors = [];
        $opacities = [];

        // First fill with the colours detected in the logo …
        foreach ($manual->logo->variant($logoVariant)->detectedColors ?? [] as $detectedColor) {
            $detectedColor = strtoupper(trim($detectedColor, '#'));
            $detectedColors[] = $detectedColor;
            $colors[$detectedColor] = $detectedColor;
            $opacities[$detectedColor] = $mappedOpacities[$detectedColor] ?? 100;
        }

        // … then override with the custom mapping already saved.
        foreach ($mappedColors as $mapFrom => $mapTo) {
            $mapFrom = strtoupper((string) $mapFrom);

            // Might be an outdated customization -> skip
            if (in_array($mapFrom, $detectedColors, true) === false) {
                continue;
            }

            $colors[$mapFrom] = strtoupper($mapTo);
        }

        /** @var FormInterface<LogoColorsFormData> $form */
        $form = $this->formFactory->createNamed(
            sprintf('logo_colors_%s_%s', $logoVariant->value, $colorVariant->value),
            LogoColorsFormType::class,
            new LogoColorsFormData($background, $colors, $opacities),
            [
                'action' => $this->urlGenerator->generate('manual_logo_colors_mapping_edit', [
                    'manualId' => $manual->id,
                    'logoVariant' => $logoVariant->value,
                    'colorVariant' => $colorVariant->value,
                ]),
            ],
        );

        return new LogoColorsEditForm(
            $form,
            $logoVariant,
            $colorVariant,
            $detectedColors,
            DefaultLogoColors::background($logoVariant, $colorVariant, $manual),
        );
    }
}
