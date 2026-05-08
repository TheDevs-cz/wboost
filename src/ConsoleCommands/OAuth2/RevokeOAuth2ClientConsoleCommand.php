<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands\OAuth2;

use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;
use League\Bundle\OAuth2ServerBundle\Service\CredentialsRevokerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('app:oauth-client:revoke', 'Deactivate an OAuth2 client and revoke its outstanding tokens')]
final class RevokeOAuth2ClientConsoleCommand extends Command
{
    public function __construct(
        readonly private ClientManagerInterface $clientManager,
        readonly private CredentialsRevokerInterface $credentialsRevoker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('client-id', InputArgument::REQUIRED, 'Client identifier to revoke');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $clientId */
        $clientId = $input->getArgument('client-id');

        $oauth2Client = $this->clientManager->find($clientId);

        if ($oauth2Client === null) {
            $io->error(sprintf('OAuth2 client "%s" was not found.', $clientId));

            return self::FAILURE;
        }

        \assert($oauth2Client instanceof AbstractClient);

        $oauth2Client->setActive(false);
        $this->clientManager->save($oauth2Client);

        $this->credentialsRevoker->revokeCredentialsForClient($oauth2Client);

        $io->success(sprintf('OAuth2 client "%s" deactivated and its tokens revoked.', $clientId));

        return self::SUCCESS;
    }
}
