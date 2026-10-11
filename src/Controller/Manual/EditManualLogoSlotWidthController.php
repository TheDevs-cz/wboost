<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Manual;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Manual;
use WBoost\Web\Message\Manual\EditManualLogoSlotWidth;
use WBoost\Web\Services\Security\ManualVoter;
use WBoost\Web\Value\LogoColorVariant;
use WBoost\Web\Value\LogoTypeVariant;
use WBoost\Web\Value\ManualPage;

/**
 * Saves the width of ONE logo card — the top of the width cascade (see
 * `Manual::logoDisplayWidth()`). Called by `manual_logo_width_controller.js`
 * from the bottom bar on the manual preview: the bar has already previewed
 * the width live, so this only persists it and answers JSON. The page never
 * reloads — a redirect would throw the admin back to the top of a manual
 * that is dozens of screens long.
 *
 * An empty `displayWidth` clears the card's own width (the card falls back to
 * its logo variant). The slot id is validated against the enums it is built
 * from, so a request cannot plant arbitrary keys in `manual.logo_slot_widths`.
 */
final class EditManualLogoSlotWidthController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'manual_logo_slot_width';

    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/manual/{manualId}/sirka-loga', name: 'manual_logo_slot_width', methods: ['POST'])]
    #[IsGranted(ManualVoter::EDIT, 'manual')]
    public function __invoke(
        #[MapEntity(id: 'manualId')]
        Manual $manual,
        Request $request,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $slot = $request->request->getString('slot');
        $logoVariant = self::logoVariantOf($slot);

        if ($logoVariant === null) {
            return new JsonResponse(['error' => 'Neznámý rámeček loga.'], Response::HTTP_BAD_REQUEST);
        }

        $rawWidth = trim($request->request->getString('displayWidth'));
        $displayWidth = null;

        if ($rawWidth !== '') {
            $displayWidth = filter_var($rawWidth, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 100],
            ]);

            if ($displayWidth === false) {
                return new JsonResponse(['error' => 'Zadejte hodnotu mezi 0 a 100.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $this->bus->dispatch(new EditManualLogoSlotWidth($manual->id, $slot, $displayWidth));

        return new JsonResponse([
            'slotWidth' => $manual->logoSlotWidth($slot),
            'displayWidth' => $manual->logoDisplayWidth($slot, $logoVariant->value),
        ]);
    }

    /**
     * `<page>.<logoVariant>.<colorVariant|base>` → the logo variant, or null
     * when any of the three parts is not a known case.
     */
    private static function logoVariantOf(string $slot): null|LogoTypeVariant
    {
        $parts = explode('.', $slot);

        if (count($parts) !== 3) {
            return null;
        }

        [$page, $logoVariant, $colorVariant] = $parts;

        if (ManualPage::tryFrom($page) === null) {
            return null;
        }

        if ($colorVariant !== 'base' && LogoColorVariant::tryFrom($colorVariant) === null) {
            return null;
        }

        return LogoTypeVariant::tryFrom($logoVariant);
    }
}
