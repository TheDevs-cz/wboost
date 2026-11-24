<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Message\Calendar\BackfillCalendarPhotoCaptions;
use WBoost\Web\MessageHandler\Calendar\BackfillCalendarPhotoCaptionsHandler;
use WBoost\Web\Services\Calendar\CalendarModelBridge;

/**
 * One-off (feedback K18): every week photo with an EMPTY caption gets the
 * caption a new upload would get today — the picture's embedded description,
 * else the cleaned original file name (`assets/calendar/model/photo-caption.js`,
 * run through node so the rule cannot drift). A non-empty caption is never
 * touched; calendars with captions switched off are skipped (as in the
 * browser). Dry run by default; `--apply` writes each calendar through
 * {@see BackfillCalendarPhotoCaptions} (row lock, revision, one history line).
 */
#[AsCommand('app:calendar:backfill-photo-captions', 'Start empty week-photo captions from the file names (dry run unless --apply)')]
final class BackfillCalendarPhotoCaptionsConsoleCommand extends Command
{
    private const int CAPTION_MAX = 80;

    public function __construct(
        readonly private Connection $connection,
        readonly private CalendarModelBridge $bridge,
        readonly private MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the captions (without it nothing is written)')
            ->addOption('calendar', null, InputOption::VALUE_REQUIRED, 'Only this calendar (uuid)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $only = $input->getOption('calendar');

        if ($only !== null && (!is_string($only) || !Uuid::isValid($only))) {
            $io->error('--calendar must be a calendar uuid.');

            return Command::INVALID;
        }

        $sql = "SELECT id, features, content->'pagePhotos' AS photos, content->'pageCaptions' AS captions FROM calendar";
        $params = [];

        if (is_string($only)) {
            $sql .= ' WHERE id = :id';
            $params['id'] = $only;
        }

        $sql .= ' ORDER BY id';

        /** @var list<array{calendarId: string, pageKey: string, assetId: string}> $candidates */
        $candidates = [];
        $skippedOff = 0;

        foreach ($this->connection->fetchAllAssociative($sql, $params) as $row) {
            $features = json_decode(self::str($row['features'], '{}'), true);
            $photos = json_decode(self::str(($row['photos'] ?? 'null'), 'null'), true);
            $captions = json_decode(self::str(($row['captions'] ?? 'null'), 'null'), true);

            if (!is_array($photos) || $photos === []) {
                continue;
            }

            if (is_array($features) && ($features['captions'] ?? true) === false) {
                $skippedOff++;

                continue;
            }

            foreach ($photos as $pageKey => $photo) {
                $caption = is_array($captions) && is_array($captions[$pageKey] ?? null) ? $captions[$pageKey] : null;

                if (!is_array($photo) || !is_string($photo['asset'] ?? null) || BackfillCalendarPhotoCaptionsHandler::hasText($caption)) {
                    continue;
                }

                $candidates[] = ['calendarId' => self::str($row['id']), 'pageKey' => (string) $pageKey, 'assetId' => $photo['asset']];
            }
        }

        $assets = [];

        if ($candidates !== []) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, calendar_id, original_name, description FROM calendar_asset WHERE id IN (:ids)',
                ['ids' => array_values(array_unique(array_column($candidates, 'assetId')))],
                ['ids' => ArrayParameterType::STRING],
            );

            foreach ($rows as $asset) {
                $assets[self::str($asset['id'])] = $asset;
            }
        }

        $names = [];

        foreach ($candidates as $candidate) {
            $asset = $assets[$candidate['assetId']] ?? null;
            $names[] = $asset !== null ? self::str($asset['original_name']) : '';
        }

        $fromNames = $this->bridge->captionsFromFileNames($names, self::CAPTION_MAX);

        $table = [];
        /** @var array<string, array<string, string>> $plan */
        $plan = [];

        foreach ($candidates as $index => $candidate) {
            $asset = $assets[$candidate['assetId']] ?? null;

            // an asset of another calendar (or a missing one) is never trusted
            if ($asset === null || self::str($asset['calendar_id']) !== $candidate['calendarId']) {
                $table[] = [$candidate['calendarId'], $candidate['pageKey'], '(asset missing)', '—'];

                continue;
            }

            $description = is_string($asset['description']) ? trim($asset['description']) : '';
            $caption = $description !== '' ? mb_substr($description, 0, self::CAPTION_MAX) : $fromNames[$index];
            $table[] = [$candidate['calendarId'], $candidate['pageKey'], self::str($asset['original_name']), $caption ?? '— (camera-style name, none)'];

            if ($caption !== null) {
                $plan[$candidate['calendarId']][$candidate['pageKey']] = $caption;
            }
        }

        if ($table !== []) {
            $io->table(['Calendar', 'Page', 'File name', 'Caption'], $table);
        }

        $count = array_sum(array_map('count', $plan));
        $io->writeln(sprintf('%d caption(s) in %d calendar(s) to fill; %d calendar(s) with captions switched off skipped.', $count, count($plan), $skippedOff));

        if (!$apply) {
            $io->note('Dry run — nothing written. Run again with --apply to write.');

            return Command::SUCCESS;
        }

        foreach ($plan as $calendarId => $captions) {
            $this->bus->dispatch(new BackfillCalendarPhotoCaptions(Uuid::fromString($calendarId), $captions));
        }

        $io->success(sprintf('Written to %d calendar(s).', count($plan)));

        return Command::SUCCESS;
    }

    private static function str(mixed $value, string $fallback = ''): string
    {
        return is_scalar($value) ? (string) $value : $fallback;
    }
}
