<?php

declare(strict_types=1);

namespace WBoost\Web\Services\AiScan;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;
use WBoost\Web\Value\AiScan\AiScanAlertCode;
use WBoost\Web\Value\AiScan\AiScanLimits;
use WBoost\Web\Value\AiScan\AiScanMode;

/**
 * Operating e-mails to the admins' notification list (architecture.md §7
 * "Observability"): the scanner stopped calling, the provider refuses our
 * account, the circuit breaker paused the feature, the day's spend is at
 * 80 % / at the ceiling, scans wait too long, the scanner is too old.
 *
 * {@see run()} evaluates every condition from the database
 * ({@see AiScanConditions}) and sends what is due. It is what
 * `app:ai-scan:watch` runs every five minutes, and it is safe to run twice at
 * once:
 *
 * - **once per condition while it lasts** — the condition's row in
 *   `ai_scan_alert` says whether it was announced. Every mail is CLAIMED by a
 *   conditional update of that row first; whoever changed the row sends, so
 *   two processes never send the same mail. A mail that could not be handed
 *   to the mailer gives its claim back, and the next run tries again;
 * - **one reminder** per condition per 24 hours while it lasts;
 * - **one "back to normal"** when an ANNOUNCED condition ends (the 80 % mark
 *   ends silently);
 * - a condition that comes back is not announced again sooner than
 *   {@see REOPEN_QUIET_SECONDS} after its last mail — a flapping service does
 *   not flood;
 * - **nothing while the operating mode is `off`** — the feature is then
 *   switched off on purpose — except the end of a condition that was
 *   announced.
 *
 * **A project paused for refusals** ({@see PROJECT_PAUSED}) is not a
 * condition of the service but of one project and one day: the provider
 * refused three of its pictures today (Europe/Prague), so its starts are
 * refused with `project_paused` until midnight. The pause is counted, never
 * stored. The admins get **one mail per project per day** — claimed by
 * inserting the `(project, day)` row of `ai_scan_project_pause`
 * ({@see claimProjectPause()}); no reminder and no "back to normal", because
 * it ends by itself. Like everything else it is not announced while the mode
 * is `off`; if the mode comes back the same day, it is announced then.
 *
 * The mails carry codes, numbers and times only — the pause's also the
 * project's name and id.
 */
final readonly class AiScanAlerts
{
    public const int REOPEN_QUIET_SECONDS = 1800;
    /** The code of the mail about a project paused for refusals — also the start refusal's code. */
    public const string PROJECT_PAUSED = 'project_paused';

    /**
     * @param list<string> $adminNotificationRecipients
     */
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private AiScanRuntimeState $runtime,
        private AiScanConditions $conditions,
        private MailerInterface $mailer,
        private Environment $twig,
        private AiScanUrls $urls,
        private array $adminNotificationRecipients,
    ) {
    }

    /**
     * @return array{lines: list<string>, failed: int} one line per action taken
     */
    public function run(null|DateTimeImmutable $now = null): array
    {
        $now ??= $this->clock->now();
        $runtime = $this->runtime->get();
        $lines = [];
        $failed = 0;

        if ($this->adminNotificationRecipients === []) {
            return ['lines' => [], 'failed' => 0];
        }

        foreach ($this->conditions->evaluate($now, $runtime) as $condition) {
            try {
                $action = $this->handle($condition, $runtime, $now);
            } catch (\Throwable $failure) {
                // The class only: a transport's message may quote an address.
                $lines[] = sprintf('failed %s (%s) — the claim was given back', $condition->code->value, $failure::class);
                ++$failed;

                continue;
            }

            if ($action !== null) {
                $lines[] = $action . ' ' . $condition->code->value;
            }
        }

        // A project paused for refusals: one mail per project and day.
        if ($runtime->mode !== AiScanMode::Off) {
            foreach ($this->conditions->pausedProjects($now) as $project) {
                $day = AiScanTime::startOfDay($now);

                if (!$this->claimProjectPause($project['projectId'], $day, $project['refusals'], $now)) {
                    continue;
                }

                try {
                    $this->projectPauseMail($project, $day, $runtime);
                } catch (\Throwable $failure) {
                    $this->connection->executeStatement(
                        'DELETE FROM ai_scan_project_pause WHERE project_id = :project AND day = :day',
                        ['project' => $project['projectId'], 'day' => $day->format('Y-m-d')],
                    );
                    $lines[] = sprintf('failed %s %s (%s) — the claim was given back', self::PROJECT_PAUSED, $project['projectId'], $failure::class);
                    ++$failed;

                    continue;
                }

                $lines[] = sprintf('opened %s %s', self::PROJECT_PAUSED, $project['projectId']);
            }
        }

        return ['lines' => $lines, 'failed' => $failed];
    }

    /**
     * The claim of the one mail about a project paused on `$day` (midnight,
     * Europe/Prague): the insert of its row. True = this caller inserted it
     * and sends.
     */
    public function claimProjectPause(string $projectId, DateTimeImmutable $day, int $refusals, DateTimeImmutable $now): bool
    {
        return $this->connection->executeStatement(
            'INSERT INTO ai_scan_project_pause (project_id, day, refusals, announced_at) VALUES (:project, :day, :refusals, :now)
             ON CONFLICT (project_id, day) DO NOTHING',
            [
                'project' => $projectId,
                'day' => $day->setTimezone(new DateTimeZone(AiScanLimits::TIMEZONE))->format('Y-m-d'),
                'refusals' => $refusals,
                'now' => AiScanTime::sql($now),
            ],
        ) === 1;
    }

    /**
     * The claim of the first mail about a condition. True = this caller
     * changed the row and sends.
     */
    public function claimOpen(AiScanAlertCode $code, DateTimeImmutable $since, DateTimeImmutable $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ai_scan_alert SET opened_at = :now, last_sent_at = :now, active_since = :since, cleared_at = NULL
              WHERE code = :code AND opened_at IS NULL AND (last_sent_at IS NULL OR last_sent_at <= :quiet)',
            [
                'code' => $code->value,
                'now' => AiScanTime::sql($now),
                'since' => AiScanTime::sql($since),
                'quiet' => AiScanTime::sql($now->modify(sprintf('-%d seconds', self::REOPEN_QUIET_SECONDS))),
            ],
        ) === 1;
    }

    /** The claim of a reminder: `$lastSentAt` is what the caller read. */
    public function claimReminder(AiScanAlertCode $code, DateTimeImmutable $lastSentAt, DateTimeImmutable $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ai_scan_alert SET last_sent_at = :now WHERE code = :code AND opened_at IS NOT NULL AND last_sent_at = :previous',
            ['code' => $code->value, 'now' => AiScanTime::sql($now), 'previous' => AiScanTime::sql($lastSentAt)],
        ) === 1;
    }

    /** The claim of the "back to normal" mail: `$openedAt` is what the caller read. */
    public function claimClose(AiScanAlertCode $code, DateTimeImmutable $openedAt, DateTimeImmutable $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE ai_scan_alert SET opened_at = NULL, active_since = NULL, cleared_at = :now, last_sent_at = :now
              WHERE code = :code AND opened_at = :opened',
            ['code' => $code->value, 'now' => AiScanTime::sql($now), 'opened' => AiScanTime::sql($openedAt)],
        ) === 1;
    }

    /**
     * The conditions that are announced right now, for the admin tab.
     *
     * @return array<string, DateTimeImmutable> code → announced at
     */
    public function open(): array
    {
        $open = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT code, opened_at FROM ai_scan_alert WHERE opened_at IS NOT NULL') as $code => $openedAt) {
            $at = AiScanTime::fromSql(is_string($openedAt) ? $openedAt : null);

            if ($at !== null) {
                $open[(string) $code] = $at;
            }
        }

        return $open;
    }

    /**
     * @return null|string `opened`, `reminded`, `cleared`, or null when nothing was sent
     */
    private function handle(AiScanCondition $condition, AiScanRuntimeSnapshot $runtime, DateTimeImmutable $now): null|string
    {
        $code = $condition->code;
        $row = $this->row($code);
        $off = $runtime->mode === AiScanMode::Off;

        if (!$condition->active) {
            if ($row['openedAt'] === null) {
                if ($row['activeSince'] !== null) {
                    $this->connection->executeStatement(
                        'UPDATE ai_scan_alert SET active_since = NULL WHERE code = :code AND opened_at IS NULL',
                        ['code' => $code->value],
                    );
                }

                return null;
            }

            if (!$this->claimClose($code, $row['openedAt'], $now)) {
                return null;
            }

            if (!$code->announcesRecovery()) {
                return null;
            }

            try {
                $this->mail($condition, 'cleared', $row['activeSince'] ?? $row['openedAt'], $runtime, $now);
            } catch (\Throwable $failure) {
                $this->connection->executeStatement(
                    'UPDATE ai_scan_alert SET opened_at = :opened, active_since = :since, cleared_at = NULL, last_sent_at = :sent
                      WHERE code = :code AND opened_at IS NULL AND cleared_at = :now',
                    [
                        'code' => $code->value,
                        'now' => AiScanTime::sql($now),
                        'opened' => AiScanTime::sql($row['openedAt']),
                        'since' => $row['activeSince'] === null ? null : AiScanTime::sql($row['activeSince']),
                        'sent' => $row['lastSentAt'] === null ? null : AiScanTime::sql($row['lastSentAt']),
                    ],
                );

                throw $failure;
            }

            return 'cleared';
        }

        if ($row['openedAt'] !== null) {
            $lastSentAt = $row['lastSentAt'] ?? $row['openedAt'];

            if ($off || $now->getTimestamp() - $lastSentAt->getTimestamp() < AiScanAlertCode::REMINDER_SECONDS) {
                return null;
            }

            if ($row['lastSentAt'] === null || !$this->claimReminder($code, $row['lastSentAt'], $now)) {
                return null;
            }

            try {
                $this->mail($condition, 'reminder', $row['activeSince'] ?? $row['openedAt'], $runtime, $now);
            } catch (\Throwable $failure) {
                $this->connection->executeStatement(
                    'UPDATE ai_scan_alert SET last_sent_at = :previous WHERE code = :code AND last_sent_at = :now',
                    ['code' => $code->value, 'now' => AiScanTime::sql($now), 'previous' => AiScanTime::sql($row['lastSentAt'])],
                );

                throw $failure;
            }

            return 'reminded';
        }

        // Active and not announced. Remember since when — the database knows
        // it for some conditions, for the others it is the first look.
        $since = $condition->since ?? $row['activeSince'] ?? $now;

        if ($row['activeSince'] === null) {
            $this->connection->executeStatement(
                'UPDATE ai_scan_alert SET active_since = :since WHERE code = :code AND opened_at IS NULL AND active_since IS NULL',
                ['code' => $code->value, 'since' => AiScanTime::sql($since)],
            );
        }

        if ($off || $now->getTimestamp() - $since->getTimestamp() < $code->holdSeconds()) {
            return null;
        }

        if (!$this->claimOpen($code, $since, $now)) {
            return null;
        }

        try {
            $this->mail($condition, 'opened', $since, $runtime, $now);
        } catch (\Throwable $failure) {
            $this->connection->executeStatement(
                'UPDATE ai_scan_alert SET opened_at = NULL, last_sent_at = :sent WHERE code = :code AND opened_at = :now',
                [
                    'code' => $code->value,
                    'now' => AiScanTime::sql($now),
                    'sent' => $row['lastSentAt'] === null ? null : AiScanTime::sql($row['lastSentAt']),
                ],
            );

            throw $failure;
        }

        return 'opened';
    }

    /**
     * @return array{activeSince: null|DateTimeImmutable, openedAt: null|DateTimeImmutable, lastSentAt: null|DateTimeImmutable}
     */
    private function row(AiScanAlertCode $code): array
    {
        $this->connection->executeStatement(
            'INSERT INTO ai_scan_alert (code) VALUES (:code) ON CONFLICT (code) DO NOTHING',
            ['code' => $code->value],
        );

        $row = $this->connection->fetchAssociative(
            'SELECT active_since, opened_at, last_sent_at FROM ai_scan_alert WHERE code = :code',
            ['code' => $code->value],
        );
        $time = static fn (string $column): null|DateTimeImmutable => is_array($row) && is_string($row[$column] ?? null)
            ? AiScanTime::fromSql($row[$column])
            : null;

        return ['activeSince' => $time('active_since'), 'openedAt' => $time('opened_at'), 'lastSentAt' => $time('last_sent_at')];
    }

    private function mail(AiScanCondition $condition, string $kind, DateTimeImmutable $since, AiScanRuntimeSnapshot $runtime, DateTimeImmutable $now): void
    {
        $text = self::text($condition, $runtime);
        $subject = match ($kind) {
            'cleared' => $text['clearedSubject'],
            'reminder' => $text['subject'] . ' (stále trvá)',
            default => $text['subject'],
        };
        $zone = new DateTimeZone(AiScanLimits::TIMEZONE);

        $html = $this->twig->render('emails/ai_scan_alert.html.twig', [
            'kind' => $kind,
            'subject' => $subject,
            'code' => $condition->code->value,
            'what' => $kind === 'cleared' ? $text['cleared'] : $text['what'],
            'todo' => $kind === 'cleared' ? null : $text['todo'],
            'since' => $since->setTimezone($zone)->format('j. n. Y H:i'),
            'until' => $kind === 'cleared' ? $now->setTimezone($zone)->format('j. n. Y H:i') : null,
            'mode' => self::modeLabel($runtime->mode),
            'url' => $this->urls->admin(),
        ]);

        $this->mailer->send((new Email())->to(...$this->adminNotificationRecipients)->subject($subject)->html($html));
    }

    /**
     * @param array{projectId: string, projectName: string, refusals: int} $project
     */
    private function projectPauseMail(array $project, DateTimeImmutable $day, AiScanRuntimeSnapshot $runtime): void
    {
        $subject = sprintf('AI sken: projekt %s je do zítřka pozastaven', $project['projectName']);
        $resumes = $day->modify('+1 day');

        $html = $this->twig->render('emails/ai_scan_alert.html.twig', [
            'kind' => 'opened',
            'subject' => $subject,
            'code' => self::PROJECT_PAUSED,
            'what' => sprintf(
                'Poskytovatel AI dnes odmítl %d %s z projektu %s. Nové skeny v tomto projektu proto do půlnoci nespouštíme; kdo to zkusí, uvidí, že skeny půjdou spustit zase zítra. Ostatních projektů se to netýká a hotové návrhy fungují dál.',
                $project['refusals'],
                $project['refusals'] < 5 ? 'obrázky' : 'obrázků',
                $project['projectName'],
            ),
            'todo' => 'Podívejte se v administraci na dnešní skeny projektu — kdo je spustil a jak skončily. Vypadá-li to na zneužití, AI sken projektu vypněte. Jinak není potřeba dělat nic: pozastavení skončí samo.',
            'rows' => [
                'Projekt' => $project['projectName'],
                'ID projektu' => $project['projectId'],
                'Odmítnutí dnes' => (string) $project['refusals'],
                'Den' => $day->format('j. n. Y'),
                'Skeny půjdou znovu spustit' => $resumes->format('j. n. Y H:i'),
            ],
            'mode' => self::modeLabel($runtime->mode),
            'url' => $this->urls->adminProject($project['projectId']),
            'button' => 'Otevřít skeny projektu v administraci',
            'footer' => 'Pozastavení skončí samo o půlnoci — další zprávu o něm nepošleme.',
        ]);

        $this->mailer->send((new Email())->to(...$this->adminNotificationRecipients)->subject($subject)->html($html));
    }

    public static function modeLabel(AiScanMode $mode): string
    {
        return match ($mode) {
            AiScanMode::On => 'Zapnuto',
            AiScanMode::Paused => 'Pozastaveno',
            AiScanMode::Off => 'Vypnuto',
        };
    }

    /**
     * What happened and what to do — fixed sentences with codes and numbers.
     *
     * @return array{subject: string, what: string, todo: string, clearedSubject: string, cleared: string}
     */
    private static function text(AiScanCondition $condition, AiScanRuntimeSnapshot $runtime): array
    {
        $fact = static fn (string $name): string => (string) ($condition->facts[$name] ?? '');
        $usd = static fn (string $name): string => number_format(((int) ($condition->facts[$name] ?? 0)) / 1_000_000, 2, ',', ' ');
        $version = $fact('version') === '' ? '' : sprintf(' (verze %s)', $fact('version'));

        return match ($condition->code) {
            AiScanAlertCode::ScannerAbsent => [
                'subject' => 'AI sken: skener neodpovídá',
                'what' => sprintf('Skener%s se aplikaci neozval déle než %d minuty. Nové skeny nepřijímáme a čekající stojí; po 60 minutách čekání skončí jako nezdařené a nezapočítají se.', $version, intdiv(AiScanAlertCode::ABSENT_SECONDS, 60)),
                'todo' => 'Na serveru zkontrolujte službu scanner (docker compose ps scanner, docker compose logs --tail 200 scanner). Postup: docs/ai-scan/runbook.md, část „Scanner down“.',
                'clearedSubject' => 'AI sken: skener znovu odpovídá',
                'cleared' => 'Skener se znovu hlásí. Fronta se rozběhne sama.',
            ],
            AiScanAlertCode::ScannerTooOld => [
                'subject' => 'AI sken: skener je příliš starý',
                'what' => sprintf('Skener%s používá protokol, který aplikace už nepřijímá, nebo mu chybí požadovaný nástroj. Skeny se nespouštějí a nové nepřijímáme.', $version),
                'todo' => 'Nasaďte verzi skeneru, která odpovídá aplikaci. Postup: docs/ai-scan/runbook.md, část „Scanner too old“.',
                'clearedSubject' => 'AI sken: skener je znovu aktuální',
                'cleared' => 'Aplikace skener znovu přijímá. Fronta se rozběhne sama.',
            ],
            AiScanAlertCode::ProviderBlocked => [
                'subject' => 'AI sken: poskytovatel odmítá náš účet',
                'what' => sprintf('Poskytovatel AI odmítá náš účet déle než %d minut%s. Skeny čekají a nové nepřijímáme. Skener stav ověřuje každých 5 minut; po nápravě se fronta rozběhne sama.', intdiv(AiScanAlertCode::PROVIDER_BLOCKED_SECONDS, 60), $fact('error') === '' ? '' : sprintf(' (kód: %s)', $fact('error'))),
                'todo' => 'Zkontrolujte u poskytovatele klíč, limit útraty a zůstatek kreditu. Postup: docs/ai-scan/runbook.md, část „Provider blocked“.',
                'clearedSubject' => 'AI sken: poskytovatel náš účet znovu přijímá',
                'cleared' => 'Poskytovatel AI náš účet znovu přijímá. Fronta se rozběhne sama.',
            ],
            AiScanAlertCode::BreakerTripped => [
                'subject' => 'AI sken: pozastaveno po třech chybách za sebou',
                'what' => sprintf('Tři skeny za sebou skončily stejnou chybou (%s). Provoz jsme proto přepnuli na Pozastaveno: nové skeny nepřijímáme, čekající stojí, hotové návrhy fungují dál.', $fact('failureClass') === '' ? 'neznámý kód' : $fact('failureClass')),
                'todo' => 'Podívejte se v administraci na poslední skeny, najděte společnou příčinu a potom provoz znovu zapněte.',
                'clearedSubject' => 'AI sken: pozastavení po chybách skončilo',
                'cleared' => sprintf('Provozní režim někdo změnil ručně. Teď je: %s.', self::modeLabel($runtime->mode)),
            ],
            AiScanAlertCode::Spend80 => [
                'subject' => 'AI sken: dnešní útrata je na 80 % denního stropu',
                'what' => sprintf('Dnes je utraceno %s $ z denního stropu %s $. Až se další sken pod strop nevejde, nové skeny do půlnoci nespustíme.', $usd('spentMicroUsd'), $usd('ceilingMicroUsd')),
                'todo' => 'Je-li to očekávaný provoz, můžete denní strop v administraci zvýšit. Jinak se podívejte, kdo skenuje.',
                'clearedSubject' => 'AI sken: dnešní útrata je zpět pod 80 % stropu',
                'cleared' => 'Útrata je zpět pod 80 % denního stropu.',
            ],
            AiScanAlertCode::SpendCeiling => [
                'subject' => 'AI sken: denní strop útraty je dosažen',
                'what' => sprintf('Dnes je utraceno %s $ z denního stropu %s $ a další sken se pod něj už nevejde. Nové skeny odmítáme a čekající stojí do půlnoci, nebo dokud strop nezvýšíte.', $usd('spentMicroUsd'), $usd('ceilingMicroUsd')),
                'todo' => 'Zkontrolujte v administraci, kdo dnes skenoval. Je-li útrata v pořádku, zvyšte denní strop; jinak provoz pozastavte.',
                'clearedSubject' => 'AI sken: denní strop už skeny neblokuje',
                'cleared' => 'Pod denní strop se znovu vejde další sken (začal nový den, nebo se strop zvýšil). Skeny se spouštějí.',
            ],
            AiScanAlertCode::QueueStalled => [
                'subject' => 'AI sken: skeny čekají déle než 15 minut',
                'what' => sprintf('Nejstarší sken čeká déle než %d minut; čekajících skenů je %s. Po 60 minutách čekání sken skončí jako nezdařený a nezapočítá se.', intdiv(AiScanAlertCode::QUEUE_STALLED_SECONDS, 60), $fact('waiting')),
                'todo' => 'Zkontrolujte v administraci provozní pruh: režim, skener, poskytovatele a dnešní útratu. Postup: docs/ai-scan/runbook.md, část „Stuck queue“.',
                'clearedSubject' => 'AI sken: fronta se znovu hýbe',
                'cleared' => 'Žádný sken už nečeká déle než 15 minut.',
            ],
            AiScanAlertCode::UsageSuspect => [
                'subject' => 'AI sken: hlášená spotřeba neodpovídá ceníku',
                'what' => sprintf(
                    'Za posledních 24 hodin skener nahlásil spotřebu, které nelze věřit: neznámý model v ceníku — %d× (%s), hotový návrh bez jediného tokenu — %d×, cena jednoho běhu nad stropem %d× rozpočtu — %d×. U neznámého modelu počítáme cenu podle údaje skeneru, u běhu nad stropem jsme ji na strop zkrátili; denní stropy útraty proto nemusejí odpovídat skutečnosti.',
                    (int) ($condition->facts['unknownModel'] ?? 0),
                    $fact('model') === '' ? 'model neuveden' : $fact('model'),
                    (int) ($condition->facts['zeroUsage'] ?? 0),
                    AiScanLimits::USAGE_COST_CAP_BUDGETS,
                    (int) ($condition->facts['capped'] ?? 0),
                ),
                'todo' => 'Otevřete v administraci „Využití skenu“ u posledních skenů a porovnejte je s přehledem u poskytovatele. Chybí-li model v ceníku (resources/ai-scan/definition/definition.json, prices.models), doplňte ho; jinak hledejte chybu ve skeneru. Postup: docs/ai-scan/runbook.md, část „Spend spike“.',
                'clearedSubject' => 'AI sken: hlášená spotřeba je znovu v pořádku',
                'cleared' => 'Za posledních 24 hodin žádné podezřelé hlášení spotřeby.',
            ],
        };
    }
}
