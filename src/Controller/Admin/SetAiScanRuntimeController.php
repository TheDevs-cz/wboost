<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\AiScan\SetAiScanRuntime;
use WBoost\Web\Services\AiScan\AiScanAlerts;
use WBoost\Web\Services\AiScan\AiScanRuntimeState;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * The operating strip of the admin tab „AI skeny“ (ux.md §9.4): the mode,
 * "Jen administrátoři" and the three numbers. It writes the runtime row
 * through the same message `app:ai-scan:status --mode=…` dispatches, so a
 * change takes effect on the next request and mode `off` fails every scan in
 * progress as interrupted, refunded.
 *
 * Only the fields that are sent are changed. `mode=off` must come with
 * `confirmed=1` (the page asks first — it stops running and waiting scans).
 * A value that makes no sense is answered with a notice, never an exception.
 */
final class SetAiScanRuntimeController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'admin_ai_scan_runtime';
    public const int MAX_CEILING_USD = 1000;
    public const int MAX_RUNNING = 8;

    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private AiScanRuntimeState $runtime,
    ) {
    }

    #[Route(path: '/admin/ai-scan/runtime', name: 'admin_ai_scan_runtime', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $back = $this->redirect($this->generateUrl('admin_usage') . '#ai-skeny');
        $fields = $request->request;
        $before = $this->runtime->get();

        $mode = null;

        if ($fields->has('mode')) {
            $mode = AiScanMode::tryFrom($fields->getString('mode'));

            if ($mode === null) {
                $this->addFlash('danger', 'Neznámý provozní režim. Nic jsme nezměnili.');

                return $back;
            }

            if ($mode === AiScanMode::Off && $before->mode !== AiScanMode::Off && !$fields->getBoolean('confirmed')) {
                $this->addFlash('warning', 'Vypnutí přeruší běžící i čekající skeny — potvrďte ho prosím v dialogu. Nic jsme nezměnili.');

                return $back;
            }
        }

        $dailyCeiling = self::usd($fields->getString('daily_ceiling_usd'));
        $projectCeiling = self::usd($fields->getString('project_ceiling_usd'));
        $maxRunning = trim($fields->getString('max_running'));

        if (
            ($fields->has('daily_ceiling_usd') && $dailyCeiling === null)
            || ($fields->has('project_ceiling_usd') && $projectCeiling === null)
            || ($fields->has('max_running') && (!ctype_digit($maxRunning) || (int) $maxRunning < 1 || (int) $maxRunning > self::MAX_RUNNING))
        ) {
            $this->addFlash('danger', sprintf(
                'Stropy zadejte jako částku 0–%d $ a počet souběžných skenů jako číslo 1–%d. Nic jsme nezměnili.',
                self::MAX_CEILING_USD,
                self::MAX_RUNNING,
            ));

            return $back;
        }

        $change = new SetAiScanRuntime(
            mode: $mode,
            adminOnly: $fields->has('admin_only') ? $fields->getBoolean('admin_only') : null,
            maxRunning: $fields->has('max_running') ? (int) $maxRunning : null,
            dailyCeilingMicroUsd: $dailyCeiling,
            projectDailyMicroUsd: $projectCeiling,
        );

        if ($change->changesNothing()) {
            return $back;
        }

        $this->bus->dispatch($change);

        $this->addFlash('success', match (true) {
            $mode === AiScanMode::Off && $before->mode !== AiScanMode::Off => 'AI sken vypnut. Běžící a čekající skeny jsme přerušili a nezapočítali.',
            $mode !== null && $mode !== $before->mode => sprintf('Provoz AI skenu: %s.', AiScanAlerts::modeLabel($mode)),
            $change->adminOnly === true && !$before->adminOnly => 'AI sken teď smí spouštět jen administrátoři.',
            $change->adminOnly === false && $before->adminOnly => 'AI sken smí spouštět i editoři projektů, které ho mají zapnutý.',
            default => 'Nastavení AI skenu uloženo.',
        });

        return $back;
    }

    /** `20`, `7.5`, `7,5` → micro-USD; null when it is not an amount in range. */
    private static function usd(string $value): null|int
    {
        $value = str_replace(',', '.', trim($value));

        if (preg_match('/^\d{1,4}(\.\d{1,2})?$/', $value) !== 1 || (float) $value > self::MAX_CEILING_USD) {
            return null;
        }

        return (int) round((float) $value * 1_000_000);
    }
}
