<?php

declare(strict_types=1);

namespace WBoost\Web\Twig\Components\SocialNetwork;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use WBoost\Web\Entity\SocialNetworkTemplateVariant;
use WBoost\Web\Services\Security\SocialNetworkTemplateVariantVoter;
use WBoost\Web\Services\SocialNetwork\ResolveTextOverrides;
use WBoost\Web\Services\SocialNetwork\SocialNetworkTemplateVariantImageRendererInterface;

/**
 * Live Component that powers the user-fill / export page for a Social Network
 * Template Variant.
 *
 * Stage 5 split this off from the legacy Stimulus + client-side Fabric
 * controller. The canvas runtime is gone from this page entirely; the preview
 * is server-rendered via the same Gotenberg pipeline the API uses.
 *
 * Architecture:
 * - Each input is a `<input data-model="on(change)|textValues[<uuid>]" name="textValues[<uuid>]">`.
 *   The data-model binding drives the live preview (LiveProp updates on field
 *   blur, component re-renders, preview <img> refreshes via `previewDataUri`).
 *   The `name` puts the same field in a regular <form> that POSTs to the
 *   download route. Export is a plain form submit — no LiveAction — because
 *   Live Component's RedirectResponse path goes through `Turbo.visit`, which
 *   does not hand binary responses back to the browser as a download.
 *   `data-turbo="false"` on the form bypasses Turbo entirely and lets the
 *   browser handle the response natively (Content-Disposition: attachment).
 * - No session stash, no LiveAction — the form data IS the input state.
 *
 * Authorisation note: `#[IsGranted]` cannot be applied at class level — the
 * Symfony Security listener resolves the subject from method arguments, and a
 * Live Component's `$variant` is a hydrated LiveProp (class property), not an
 * argument. Access is enforced explicitly in `previewDataUri()` and
 * `postMount()`, which are the only paths that touch the variant.
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
     * set when the component renders (see assert()s below).
     */
    #[LiveProp]
    public null|SocialNetworkTemplateVariant $variant = null;

    /**
     * Map of inputId UUID → text value the user has typed.
     *
     * `writable: true` lets Live Components write into any sub-key of this
     * array via `data-model="textValues[<inputId>]"` in the template. The
     * Symfony hydrator merges the dirty paths the front-end sends with the
     * existing array on each render.
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
    ) {
    }

    /**
     * Pre-populate `textValues` / `hiddenValues` with an entry per
     * non-locked input so the Live Component value-store knows about every
     * inputId key from the first render. Without this, writing into
     * `textValues[<uuid>]` from the front-end fails with `Invalid model name`
     * because the JS valueStore validates writes against keys already
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
     * Render the preview as a base64 data: URI so the Twig template can drop
     * it directly into an <img> tag.
     *
     * Called every time the component renders; combined with the `on(change)`
     * data-model modifier, this fires once per field blur — the explicit
     * Stage 5 trade-off (good UX, no per-keystroke Gotenberg pressure, no
     * caching layer needed yet).
     *
     * Uses `renderToBytes()` (not `render()`) deliberately: `render()` returns
     * a Gotenberg StreamedResponse whose body callback echoes + flush()es
     * each chunk. Calling `sendContent()` on that server-side commits the
     * outer response headers to the client prematurely, so the actual HTML
     * response we are still in the middle of building loses its Content-Type
     * and cookies. The browser then content-sniffs whatever bytes leaked
     * out, which is exactly the "renders weirdly in some popup" symptom.
     * `renderToBytes()` drains the Gotenberg chunks into a string via the
     * bundle's InMemoryProcessor — no echo, no flush, no header commit.
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

        $bytes = $this->renderer->renderToBytes($variant, $overrides);

        if ($bytes === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($bytes);
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
