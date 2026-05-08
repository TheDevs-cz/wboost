<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components\SocialNetwork;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use WBoost\Web\Controller\SocialNetwork\SocialNetworkTemplateVariantDownloadController;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;

/**
 * Live Component that powers the user-fill / export page for a Social Network
 * Template Variant. Stage 5 replacement for the Stimulus + client-side Fabric
 * controller: the canvas runtime is gone from this page, the preview is
 * rendered on the server and inputs are bound via data-model with the
 * `on(change)` modifier so re-renders fire on field blur (not on each
 * keystroke), keeping Gotenberg load reasonable.
 *
 * Authorisation note: `#[IsGranted]` cannot be applied at class level here —
 * the Symfony Security listener resolves the subject from method arguments,
 * and a Live Component's `$variant` is a hydrated LiveProp (class property),
 * not an argument. Access is enforced explicitly in `previewDataUri()` and
 * `exportPng()`, which are the only paths that touch the variant.
 */
#[AsLiveComponent('SocialNetwork:VariantFiller')]
final class VariantFiller extends AbstractController
{
    use DefaultActionTrait;

    /**
     * The variant being filled. Live Components hydrate Doctrine entities by
     * id, so this value flows through round-trips as the variant's UUID.
     *
     * Declared nullable to satisfy PHPStan's uninitialized-property check —
     * Live Components hydrate the property after construction, so a non-null
     * default is not possible at the language level. In practice it is always
     * set when the component renders or an action fires (see assert()s below).
     */
    #[LiveProp]
    public null|SocialNetworkTemplateVariant $variant = null;

    /**
     * Map of inputId UUID → text value the user has typed.
     *
     * `writable: true` lets Live Components write into any key of this array
     * via `data-model="textValues.<inputId>"` in the template.
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $textValues = [];

    /**
     * Map of inputId UUID → bool (true = hide). Only inputs whose definition
     * has `hidable: true` honor this; others are silently ignored when the
     * overrides are resolved.
     *
     * @var array<string, bool>
     */
    #[LiveProp(writable: true)]
    public array $hiddenValues = [];

    public function __construct(
        private readonly ResolveTextOverrides $resolveTextOverrides,
        private readonly SocialNetworkTemplateVariantImageRendererInterface $renderer,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Pre-populate `textValues` / `hiddenValues` with an entry per
     * non-locked input so the Live Component value-store knows about every
     * inputId key from the first render. Without this, writing into
     * `textValues[<uuid>]` from the front-end fails with `Invalid model name`
     * because the JS valueStore only validates writes against keys already
     * present in the hydrated state.
     */
    #[PostMount]
    public function postMount(): void
    {
        $variant = $this->variant;

        if ($variant === null) {
            return;
        }

        $this->denyAccessUnlessGranted(SocialNetworkTemplateVariantVoter::VIEW, $variant);

        foreach ($variant->inputs as $input) {
            if ($input->locked) {
                continue;
            }

            $this->textValues[$input->inputId] ??= '';

            if ($input->hidable) {
                $this->hiddenValues[$input->inputId] ??= false;
            }
        }
    }

    /**
     * Stash the current input state in the session and redirect to the
     * dedicated download route. Live Components do not natively support
     * returning binary file responses from a LiveAction (per the bundle docs),
     * so the LiveAction acts as a "prepare download" step that hands control
     * off to a regular Symfony controller.
     */
    #[LiveAction]
    public function exportPng(): RedirectResponse
    {
        $variant = $this->variant;
        assert($variant !== null);
        $this->denyAccessUnlessGranted(SocialNetworkTemplateVariantVoter::VIEW, $variant);

        $session = $this->requestStack->getSession();
        $session->set(
            SocialNetworkTemplateVariantDownloadController::sessionKey($variant->id->toString()),
            [
                'textValues' => $this->textValues,
                'hiddenValues' => $this->hiddenValues,
            ],
        );

        return $this->redirectToRoute(
            'social_network_template_variant_download',
            ['variantId' => $variant->id->toString()],
        );
    }

    /**
     * Render the preview as a base64 data: URI so the Twig template can drop
     * it directly into an <img> tag. Called every time the component renders;
     * combined with the `on(change)` data-model modifier, this fires once per
     * field blur — which is the explicit Stage 5 trade-off (good UX, no
     * per-keystroke Gotenberg pressure, no caching layer needed yet).
     */
    public function previewDataUri(): string
    {
        $variant = $this->variant;
        assert($variant !== null);
        $this->denyAccessUnlessGranted(SocialNetworkTemplateVariantVoter::VIEW, $variant);

        $overrides = $this->resolveTextOverrides->resolve(
            $variant->inputs,
            $this->buildProvidedValues(),
        );

        $response = $this->renderer->render($variant, $overrides);
        $payload = $response->getContent();

        if ($payload === false || $payload === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($payload);
    }

    /**
     * Merge the two writable LiveProps into the shape ResolveTextOverrides
     * expects: `{ inputId: { value?: string, hide?: bool } }`.
     *
     * @return array<string, array{value?: string, hide?: bool}>
     */
    private function buildProvidedValues(): array
    {
        /** @var array<string, array{value?: string, hide?: bool}> $merged */
        $merged = [];

        foreach ($this->textValues as $inputId => $value) {
            $merged[$inputId] = ['value' => $value];
        }

        foreach ($this->hiddenValues as $inputId => $hide) {
            if (!isset($merged[$inputId])) {
                $merged[$inputId] = [];
            }
            $merged[$inputId]['hide'] = $hide;
        }

        return $merged;
    }
}
