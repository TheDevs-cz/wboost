<?php

/**
 * Development helper of the scanner: reads what the app stored of a scan, straight from the dev database
 * and the private bucket. Read-only. Runs INSIDE the app's dev container:
 *
 *   php scan.php show     <scanId>          the row (no design text), its usage rows and ledger lines
 *   php scan.php proposal <scanId>          the stored proposal + report (a private run artefact — do not commit)
 *   php scan.php image    <scanId> [kept|original|working|preview]    the bytes to stdout
 *
 * The USER's side (upload, cancel, retry, continue) goes through the app's own web endpoints:
 * scanner/dev/app-user.mjs. Refuses outside APP_ENV=dev.
 */

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Symfony\Component\Dotenv\Dotenv;
use WBoost\Web\Services\AiScan\AiScanStorage;
use WBoost\Web\SymfonyApplicationKernel;

$root = getenv('APP_ROOT') ?: '/app';
require $root . '/vendor/autoload.php';
(new Dotenv())->bootEnv($root . '/.env');
if (($_SERVER['APP_ENV'] ?? 'dev') !== 'dev') {
    fwrite(STDERR, "refusing: APP_ENV is not dev\n");
    exit(2);
}
$args = array_slice($argv, 1);
$command = array_shift($args);
$kernel = new SymfonyApplicationKernel('dev', true);
$kernel->boot();
$c = $kernel->getContainer();
/** @var Connection $db */
$db = $c->get('doctrine')->getConnection();
$out = static function (array $data, int $code = 0): never {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($code);
};
$decode = static fn (mixed $json): mixed => is_string($json) ? json_decode($json, true) : $json;

switch ($command) {
    case 'show':
    case 'proposal':
        [$scan] = $args + [null];
        $row = $db->fetchAssociative(
            'SELECT id, project_id, kind, status, stage, note, note_params, round, error_code, try, run, intake_tries, intake_losses, auto_restart, approximate,
                    unfinished, kept_candidate, jsonb_array_length(candidates) AS candidates, renders, beat_seq, lease_expires_at, waiting_since, started_at, finished_at,
                    failure_count, pre_check, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cost_micro_usd, turns, duration_ms, model,
                    definition_version, scanner_version, resources, events' . ($command === 'proposal' ? ', proposal, report' : ', (proposal IS NOT NULL) AS has_proposal, (report IS NOT NULL) AS has_report') . '
               FROM ai_scan WHERE id = :id',
            ['id' => $scan],
        );
        if ($row === false) {
            $out(['ok' => false, 'code' => 'no_such_scan'], 1);
        }
        foreach (['note_params', 'pre_check', 'resources', 'events', 'proposal', 'report'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $decode($row[$key]);
            }
        }
        if ($command === 'show') {
            // the history as codes: when, what
            $row['events'] = array_map(static fn (array $e): array => array_filter(['at' => $e['at'] ?? null, 'type' => $e['type'] ?? ($e['event'] ?? null), 'code' => $e['code'] ?? null, 'run' => $e['run'] ?? null, 'status' => $e['status'] ?? null], static fn ($v) => $v !== null), is_array($row['events']) ? $row['events'] : []);
        }
        $usage = $db->fetchAllAssociative(
            'SELECT run, step, model, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, cost_micro_usd, engine_cost_micro_usd, price_version, turns, duration_ms, outcome, error_code, occurred_at, closed_at
               FROM ai_usage_event WHERE scan_id = :id ORDER BY occurred_at, run, step',
            ['id' => $scan],
        );
        $ledger = $db->fetchAllAssociative('SELECT try, period, delta, reason, cost_micro_usd, created_at FROM ai_credit_ledger WHERE scan_id = :id ORDER BY created_at, reason', ['id' => $scan]);
        $out(['ok' => true, 'scan' => $row, 'usage' => $usage, 'ledger' => $ledger, 'credit' => array_sum(array_map(static fn (array $l): int => (int) $l['delta'], $ledger))]);
        // no break
    case 'image':
        [$scan, $which] = $args + [null, 'kept'];
        $row = $db->fetchAssociative('SELECT project_id, storage_secret, kept_candidate, source_meta FROM ai_scan WHERE id = :id', ['id' => $scan]);
        if ($row === false) {
            $out(['ok' => false, 'code' => 'no_such_scan'], 1);
        }
        /** @var AiScanStorage $storage */
        $storage = $c->get(AiScanStorage::class);
        $meta = $decode($row['source_meta']);
        $name = match ($which) {
            'working' => AiScanStorage::WORKING,
            'preview' => AiScanStorage::PREVIEW,
            'original' => AiScanStorage::originalName((string) ($meta['ext'] ?? '')),
            default => AiScanStorage::candidateName((int) $row['kept_candidate']),
        };
        $bytes = $storage->read(AiScanStorage::directory((string) $row['project_id'], (string) $scan, (string) $row['storage_secret']), $name);
        if ($bytes === null) {
            fwrite(STDERR, "no such file\n");
            exit(1);
        }
        echo $bytes;
        exit(0);
        // no break
    default:
        $out(['ok' => false, 'code' => 'usage: scan.php show|proposal|image <scanId>'], 2);
}
