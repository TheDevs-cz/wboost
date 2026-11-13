<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use WBoost\Web\Entity\AiScan;
use WBoost\Web\Message\AiScan\SendAiScanEmail;
use WBoost\Web\Repository\AiScanRepository;
use WBoost\Web\Services\AiScan\Template\ScanProposal;
use WBoost\Web\Services\AiScan\Template\ScanProposalView;
use WBoost\Web\Value\AiScan\AiScanKind;
use WBoost\Web\Value\AiScan\AiScanStatus;

/**
 * Telling the requester that a scan ended (ux.md §7):
 *
 * - **seen** — the requester's browser got the final state on the scan
 *   surface, or they clicked a toast or a card. It clears the badge and
 *   cancels the e-mail;
 * - **one e-mail** when the scan ends, not sooner than two minutes after and
 *   only if still unseen: {@see schedule()} queues a delayed
 *   {@see SendAiScanEmail} at the transition, {@see sendEnded()} decides again
 *   from the row and claims `notified_at` with a conditional update, so it
 *   cannot go out twice;
 * - **one reminder** three days before a waiting proposal expires
 *   (`reminded_at`), found by the nightly purge ({@see sendDue()}), which also
 *   sends any ended-mail whose delayed message was lost.
 *
 * To the requester only; none when the account no longer exists. The buttons
 * only open pages — nothing happens on a GET.
 */
final readonly class AiScanNotifications
{
    public const int DELAY_SECONDS = 120;
    public const int REMINDER_DAYS = 3;
    /** The backstop does not dig up scans that ended long ago. */
    private const int BACKSTOP_HOURS = 48;
    private const array ENDED = ['ready', 'stopped', 'failed', 'refused'];

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private MailerInterface $mailer,
        private Environment $twig,
        private AiScanRepository $repository,
        private AiScanUrls $urls,
        private ScanProposal $proposal,
        private ScanProposalView $proposalView,
    ) {
    }

    /**
     * Called by the transition that ended a scan. Never a reason for that
     * transition to fail: the purge sends what was not queued.
     */
    public function schedule(string $scanId): void
    {
        try {
            $this->bus->dispatch(new SendAiScanEmail(Uuid::fromString($scanId)), [new DelayStamp(self::DELAY_SECONDS * 1000)]);
        } catch (\Throwable) {
            // The backstop of the nightly purge covers it.
        }
    }

    /**
     * The requester saw the result. Only the requester's own look counts.
     */
    public function seen(UuidInterface $scanId, UuidInterface $userId): bool
    {
        return $this->connection->executeStatement(
            "UPDATE ai_scan SET seen_at = :now
              WHERE id = :id AND requested_by_id = :user AND seen_at IS NULL AND status NOT IN ('checking', 'queued', 'running')",
            ['id' => $scanId->toString(), 'user' => $userId->toString(), 'now' => AiScanTime::sql($this->clock->now())],
        ) === 1;
    }

    public function sendEnded(UuidInterface $scanId): bool
    {
        $now = $this->clock->now();

        // The claim: whoever changes the row sends the mail.
        $claimed = $this->connection->executeStatement(
            sprintf(
                "UPDATE ai_scan SET notified_at = :now
                  WHERE id = :id AND notified_at IS NULL AND seen_at IS NULL AND requested_by_id IS NOT NULL
                    AND status IN ('%s') AND finished_at IS NOT NULL AND finished_at <= :due",
                implode("', '", self::ENDED),
            ),
            [
                'id' => $scanId->toString(),
                'now' => AiScanTime::sql($now),
                // A few seconds of slack: the delayed message is not late by its own delay.
                'due' => AiScanTime::sql($now->modify(sprintf('-%d seconds', self::DELAY_SECONDS - 10))),
            ],
        ) === 1;

        if (!$claimed) {
            return false;
        }

        try {
            $this->mail($this->repository->get($scanId), false);
        } catch (\Throwable $failure) {
            $this->connection->executeStatement('UPDATE ai_scan SET notified_at = NULL WHERE id = :id', ['id' => $scanId->toString()]);

            throw $failure;
        }

        return true;
    }

    public function sendReminder(UuidInterface $scanId): bool
    {
        $now = $this->clock->now();

        $claimed = $this->connection->executeStatement(
            "UPDATE ai_scan SET reminded_at = :now
              WHERE id = :id AND status = 'ready' AND reminded_at IS NULL AND requested_by_id IS NOT NULL
                AND expires_at IS NOT NULL AND expires_at > :now AND expires_at <= :soon",
            [
                'id' => $scanId->toString(),
                'now' => AiScanTime::sql($now),
                'soon' => AiScanTime::sql($now->modify(sprintf('+%d days', self::REMINDER_DAYS))),
            ],
        ) === 1;

        if (!$claimed) {
            return false;
        }

        try {
            $this->mail($this->repository->get($scanId), true);
        } catch (\Throwable $failure) {
            $this->connection->executeStatement('UPDATE ai_scan SET reminded_at = NULL WHERE id = :id', ['id' => $scanId->toString()]);

            throw $failure;
        }

        return true;
    }

    /**
     * Everything that is due now: the nightly purge's part. One failing mail
     * does not stop the others.
     */
    public function sendDue(): int
    {
        $now = $this->clock->now();
        $sent = 0;

        /** @var list<string> $reminders */
        $reminders = $this->connection->fetchFirstColumn(
            "SELECT id FROM ai_scan WHERE status = 'ready' AND reminded_at IS NULL AND requested_by_id IS NOT NULL
               AND expires_at IS NOT NULL AND expires_at > :now AND expires_at <= :soon ORDER BY expires_at LIMIT 200",
            ['now' => AiScanTime::sql($now), 'soon' => AiScanTime::sql($now->modify(sprintf('+%d days', self::REMINDER_DAYS)))],
        );

        /** @var list<string> $ended */
        $ended = $this->connection->fetchFirstColumn(
            sprintf(
                "SELECT id FROM ai_scan WHERE status IN ('%s') AND notified_at IS NULL AND seen_at IS NULL AND requested_by_id IS NOT NULL
                   AND finished_at <= :due AND finished_at >= :since ORDER BY finished_at LIMIT 200",
                implode("', '", self::ENDED),
            ),
            [
                'due' => AiScanTime::sql($now->modify(sprintf('-%d seconds', self::DELAY_SECONDS))),
                'since' => AiScanTime::sql($now->modify(sprintf('-%d hours', self::BACKSTOP_HOURS))),
            ],
        );

        foreach ([[$ended, false], [$reminders, true]] as [$ids, $reminder]) {
            foreach ($ids as $id) {
                try {
                    $sent += ($reminder ? $this->sendReminder(Uuid::fromString($id)) : $this->sendEnded(Uuid::fromString($id))) ? 1 : 0;
                } catch (\Throwable) {
                    // Left unclaimed: the next night tries again.
                }
            }
        }

        return $sent;
    }

    private function mail(AiScan $scan, bool $reminder): void
    {
        $user = $scan->requestedBy;

        if ($user === null) {
            return;
        }

        $file = is_string($scan->sourceMeta['name'] ?? null) && $scan->sourceMeta['name'] !== '' ? $scan->sourceMeta['name'] : 'obrázek';
        // A calendar scan proposes the layout of a calendar: the mail names
        // the calendar and has nothing to decide (the template proposal's
        // reader does not know a calendar proposal).
        $calendar = $scan->kind === AiScanKind::Calendar;
        $name = match (true) {
            $calendar => $scan->calendar === null ? $file : $scan->calendar->name,
            $scan->status === AiScanStatus::Ready => $this->proposal->name($scan),
            default => $file,
        };
        $view = !$calendar && $scan->status === AiScanStatus::Ready ? $this->proposalView->view($scan) : null;
        $toDecide = is_int($view['toDecide'] ?? null) ? $view['toDecide'] : 0;

        [$kind, $subject] = match (true) {
            $reminder => ['reminder', sprintf($calendar ? 'Návrh rozvržení kalendáře „%s“ za 3 dny vyprší' : 'Návrh „%s“ za 3 dny vyprší', $name)],
            $scan->status === AiScanStatus::Ready => ['ready', $calendar ? 'Návrh rozvržení kalendáře je připravený' : 'Návrh šablony z obrázku je připravený'],
            $scan->status === AiScanStatus::Stopped => ['stopped', 'Sken potřebuje jiný obrázek'],
            default => ['failed', 'Sken se nepodařil'],
        };

        $html = $this->twig->render('emails/ai_scan.html.twig', [
            'kind' => $kind,
            'calendar' => $calendar,
            'subject' => $subject,
            'file' => $file,
            'name' => $name,
            'project' => $scan->project->name,
            'toDecide' => self::toDecide($toDecide),
            'reason' => self::reason($scan),
            'expires' => $scan->expiresAt?->setTimezone(new \DateTimeZone('Europe/Prague'))->format('j. n. Y'),
            'url' => $this->urls->scan($scan),
        ]);

        $this->mailer->send((new Email())->to($user->email)->subject($subject)->html($html));
    }

    /** `Zbývá rozhodnout 1 věc.` — ux.md §2.4; nothing when nothing is left. */
    public static function toDecide(int $count): null|string
    {
        return match (true) {
            $count < 1 => null,
            $count === 1 => 'Zbývá rozhodnout 1 věc.',
            $count < 5 => sprintf('Zbývají rozhodnout %d věci.', $count),
            default => sprintf('Zbývá rozhodnout %d věcí.', $count),
        };
    }

    /** The sentence of the result card (ux.md S5, F3–F8) for the scan's code. */
    public static function reason(AiScan $scan): string
    {
        if ($scan->status === AiScanStatus::Refused) {
            return 'Služba AI ho vyhodnotila jako obsah, se kterým nepracuje.';
        }

        return match ($scan->errorCode) {
            'photo_of_print' => 'Vypadá jako fotka tisku — fotka z ruky zkreslí rozměry i barvy. Nejlépe funguje export nebo snímek obrazovky.',
            'blurry_or_small' => 'Je příliš rozmazaný, texty v něm nepřečteme. Nahrajte ostřejší nebo větší verzi.',
            'not_expected_kind' => $scan->kind === AiScanKind::Calendar
                ? 'Nenašli jsme v něm stránku týdenního kalendáře. Nahrajte obrázek jedné stránky s dny v týdnu.'
                : 'Nenašli jsme v něm grafiku — je prázdný, nebo je to jen fotografie bez textů a tvarů, snímek obrazovky, stránka dokumentu či graf.',
            'several_designs' => $scan->kind === AiScanKind::Calendar
                ? 'Je v něm víc stránek. Nahrajte obrázek jedné stránky kalendáře.'
                : 'Je v něm víc grafik. Z jednoho obrázku umíme udělat jednu šablonu.',
            'unusable_input' => 'Soubor je poškozený nebo v neobvyklém formátu. Uložte ho znovu jako PNG nebo JPG.',
            'provider_unavailable' => 'Služba AI teď neodpovídá. Váš obrázek máme uložený — stačí to zkusit znovu.',
            'renderer_unavailable' => 'Náhled se teď nepodařilo vykreslit. Váš obrázek máme uložený — zkuste to znovu.',
            'timeout' => 'Sken trval mnohem déle než obvykle, proto jsme ho zastavili. Pomůže jednodušší nebo menší obrázek.',
            'queue_timeout' => 'Čekal ve frontě déle než hodinu, proto jsme ho zastavili. Váš obrázek máme uložený — zkuste to znovu.',
            'build' => 'Obrázek jsme přečetli, ale návrh se nepodařilo poskládat.',
            default => 'Na naší straně se něco pokazilo. Váš obrázek máme uložený.',
        };
    }
}
