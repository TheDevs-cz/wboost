<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Palette;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use WBoost\Web\Entity\ProjectColor;
use WBoost\Web\Exceptions\ProjectColorHexLocked;
use WBoost\Web\Message\Palette\EditProjectColor;
use WBoost\Web\Repository\ProjectColorRepository;
use WBoost\Web\Services\Palette\PaletteColorInput;
use WBoost\Web\Services\Security\ProjectVoter;
use WBoost\Web\Value\ManualColorType;
use WBoost\Web\Value\ProjectColorField;

/**
 * Autosave of ONE field of a palette colour card (F7 P2) — `field` =
 * hex | name | role | cmyk | pantone | ral, `value` (or `cmyk[]`, four
 * channels). Answers JSON with the colour as stored, the page stays put and
 * shows the "Uloženo" toast (pattern: `EditManualLogoSlotWidthController`).
 *
 * The edit reaches every manual linking the colour — its role decides which
 * colour page of each of them shows it (D2), its print data and (for an added
 * colour) its HEX are what they print. The HEX of a colour a logo uses is
 * locked: the logo decides it, recolouring matches its literal.
 */
final class EditProjectColorController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProjectColorRepository $projectColorRepository,
    ) {
    }

    #[Route(path: '/paleta/barva/{colorId}', name: 'project_palette_color_edit', methods: ['POST'])]
    public function __invoke(
        #[MapEntity(id: 'colorId')]
        ProjectColor $color,
        Request $request,
    ): Response {
        $this->denyAccessUnlessGranted(ProjectVoter::EDIT, $color->project);

        if (!$this->isCsrfTokenValid(ProjectPaletteController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $field = ProjectColorField::tryFrom($request->request->getString('field'));

        if ($field === null) {
            return self::error('Neznámé pole barvy.');
        }

        $raw = $request->request->getString('value');
        $value = null;
        $cmyk = null;

        switch ($field) {
            case ProjectColorField::Hex:
                $value = PaletteColorInput::hex($raw);

                if ($value === null) {
                    return self::error('Zadejte HEX ve tvaru #1A2B3C.');
                }
                break;

            case ProjectColorField::Role:
                if ($raw !== '' && ManualColorType::tryFrom($raw) === null) {
                    return self::error('Neznámá role barvy.');
                }

                $value = $raw === '' ? null : $raw;
                break;

            case ProjectColorField::Cmyk:
                $cmyk = PaletteColorInput::cmyk($request->request->all('cmyk'));

                if ($cmyk === false) {
                    return self::error('CMYK má čtyři hodnoty, každou nejvýš o ' . PaletteColorInput::CMYK_CHANNEL_MAX_LENGTH . ' znacích.');
                }
                break;

            case ProjectColorField::Name:
            case ProjectColorField::Pantone:
            case ProjectColorField::Ral:
                $value = PaletteColorInput::text($raw);

                if ($value === false) {
                    return self::error('Hodnota může mít nejvýš ' . PaletteColorInput::TEXT_MAX_LENGTH . ' znaků.');
                }
                break;
        }

        try {
            $this->bus->dispatch(new EditProjectColor($color->id, $field, $value, $cmyk));
        } catch (HandlerFailedException $exception) {
            if ($exception->getPrevious() instanceof ProjectColorHexLocked) {
                return self::error('HEX určuje logotyp — změní se až s novým logem.');
            }

            throw $exception->getPrevious() ?? $exception;
        }

        $saved = $this->projectColorRepository->get($color->id);

        return new JsonResponse(['color' => self::payload($saved)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(ProjectColor $color): array
    {
        return [
            'id' => $color->id->toString(),
            'hex' => $color->hex,
            'rgb' => $color->rgb(),
            'name' => $color->name,
            'role' => $color->role?->value,
            'cmyk' => $color->cmyk,
            'pantone' => $color->pantone,
            'ral' => $color->ral,
        ];
    }

    private static function error(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], Response::HTTP_BAD_REQUEST);
    }
}
