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
use WBoost\Web\Message\Template\ConvertTemplateVariantBackgroundToLayer;

/**
 * Upgrades legacy canvas-mode variants (created before the 2026-08-03
 * background-as-layer rework) to layer mode, keeping their rendered look.
 * Scope is mandatory — a group, a template, single variants, or --all — and
 * --dry-run lists what would change. Idempotent: layer-mode variants are
 * never selected, background-less canvas-mode ones are reported and skipped.
 *
 * Variants of one group share one background inputId (the group-sync join
 * key) — reused from an already layer-mode sibling when the group is mixed.
 */
#[AsCommand('app:template:convert-background-to-layer', 'Convert legacy canvas-mode template backgrounds into a background layer')]
final class ConvertBackgroundToLayerConsoleCommand extends Command
{
    public function __construct(
        readonly private Connection $connection,
        readonly private MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Template group id')
            ->addOption('template', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Template id')
            ->addOption('variant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Template variant id')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every canvas-mode variant')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be converted');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $groups */
        $groups = $input->getOption('group');
        /** @var list<string> $templates */
        $templates = $input->getOption('template');
        /** @var list<string> $variants */
        $variants = $input->getOption('variant');
        $all = (bool) $input->getOption('all');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$all && $groups === [] && $templates === [] && $variants === []) {
            $io->error('Pick a scope: --group, --template, --variant (repeatable) or --all.');

            return Command::INVALID;
        }

        foreach ([...$groups, ...$templates, ...$variants] as $id) {
            if (!Uuid::isValid($id)) {
                $io->error(sprintf('"%s" is not a UUID.', $id));

                return Command::INVALID;
            }
        }

        $where = ["v.background_mode = 'canvas'"];
        $params = [];
        $types = [];

        if (!$all) {
            $scope = [];
            foreach (['group' => [$groups, 'v.group_id'], 'template' => [$templates, 'v.template_id'], 'variant' => [$variants, 'v.id']] as $key => [$ids, $column]) {
                if ($ids !== []) {
                    $scope[] = sprintf('%s IN (:%s)', $column, $key);
                    $params[$key] = $ids;
                    $types[$key] = ArrayParameterType::STRING;
                }
            }
            $where[] = '(' . implode(' OR ', $scope) . ')';
        }

        /** @var list<array{id: string, group_id: null|string, template_id: string, template_name: string, background_image: null|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.id, v.group_id, v.template_id, t.name AS template_name, v.background_image
             FROM template_variant v JOIN template t ON t.id = v.template_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY v.template_id, v.created_at',
            $params,
            $types,
        );

        if ($rows === []) {
            $io->success('Nothing to convert — no canvas-mode variant in scope.');

            return Command::SUCCESS;
        }

        $inputIdByGroup = [];
        $converted = 0;
        $skipped = 0;
        $table = [];

        foreach ($rows as $row) {
            if ($row['background_image'] === null || $row['background_image'] === '') {
                $skipped++;
                $table[] = [$row['id'], $row['template_name'], 'skipped — no background'];

                continue;
            }

            $inputId = $row['group_id'] !== null
                ? ($inputIdByGroup[$row['group_id']] ??= $this->existingGroupBackgroundInputId($row['group_id']) ?? Uuid::uuid4()->toString())
                : Uuid::uuid4()->toString();

            if (!$dryRun) {
                $this->commandBus->dispatch(new ConvertTemplateVariantBackgroundToLayer(Uuid::fromString($row['id']), $inputId));
            }

            $converted++;
            $table[] = [$row['id'], $row['template_name'], $dryRun ? 'would convert' : 'converted'];
        }

        $io->table(['Variant', 'Template', 'Result'], $table);
        $io->success(sprintf(
            '%s %d variant(s); %d skipped (no background).',
            $dryRun ? 'Would convert' : 'Converted',
            $converted,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * A mixed group (some siblings already layer-mode) keeps its existing
     * background inputId so the join key stays single.
     */
    private function existingGroupBackgroundInputId(string $groupId): null|string
    {
        $inputId = $this->connection->fetchOne(
            "SELECT o->>'inputId'
             FROM template_variant v, jsonb_array_elements(v.canvas->'objects') o
             WHERE v.group_id = :group AND v.background_mode = 'layer'
               AND jsonb_typeof(v.canvas->'objects') = 'array'
               AND (o->>'isBackground') = 'true' AND o->>'inputId' IS NOT NULL
             LIMIT 1",
            ['group' => $groupId],
        );

        return is_string($inputId) && $inputId !== '' ? $inputId : null;
    }
}
