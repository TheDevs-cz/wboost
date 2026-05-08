<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands\OAuth2;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Entity\OAuth2ClientUser;

#[AsCommand('app:oauth-client:list', 'List all OAuth2 clients with their linked user')]
final class ListOAuth2ClientsConsoleCommand extends Command
{
    public function __construct(
        readonly private ClientManagerInterface $clientManager,
        readonly private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clients = $this->clientManager->list(null);

        if (count($clients) === 0) {
            $io->info('No OAuth2 clients registered.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($clients as $client) {
            $identifier = $client->getIdentifier();
            $mapping = $this->entityManager->find(OAuth2ClientUser::class, $identifier);

            $rows[] = [
                $identifier,
                $client->getName(),
                $mapping?->user->email ?? '—',
                $client->isActive() ? 'yes' : 'no',
                implode(', ', array_map(static fn (object $g): string => (string) $g, $client->getGrants())),
            ];
        }

        $io->table(['Client ID', 'Name', 'Linked user', 'Active', 'Grants'], $rows);

        return self::SUCCESS;
    }
}
