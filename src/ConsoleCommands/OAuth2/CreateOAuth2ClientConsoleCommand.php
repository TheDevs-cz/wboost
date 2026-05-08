<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands\OAuth2;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client as OAuth2Client;
use League\Bundle\OAuth2ServerBundle\OAuth2Grants;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WBoost\Web\Entity\OAuth2ClientUser;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Repository\UserRepository;

#[AsCommand('app:oauth-client:create', 'Create an OAuth2 client (client_credentials grant) linked to a user')]
final class CreateOAuth2ClientConsoleCommand extends Command
{
    public function __construct(
        readonly private ClientManagerInterface $clientManager,
        readonly private UserRepository $userRepository,
        readonly private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user-email', InputArgument::REQUIRED, 'Email of the user this client represents')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Human-readable client name', 'service-client');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('user-email');

        /** @var string $name */
        $name = $input->getOption('name');

        try {
            $user = $this->userRepository->get($email);
        } catch (UserNotFound) {
            $io->error(sprintf('User with email "%s" was not found.', $email));

            return self::FAILURE;
        }

        $identifier = bin2hex(random_bytes(16));
        $plainTextSecret = bin2hex(random_bytes(32));

        $client = new OAuth2Client($name, $identifier, $plainTextSecret);
        $client->setActive(true);
        $client->setGrants(new Grant(OAuth2Grants::CLIENT_CREDENTIALS));

        $this->clientManager->save($client);

        $mapping = new OAuth2ClientUser($identifier, $user);
        $this->entityManager->persist($mapping);
        $this->entityManager->flush();

        $io->success('OAuth2 client created.');
        $io->definitionList(
            ['Client ID' => $identifier],
            ['Client Secret' => $plainTextSecret],
            ['Linked user' => $user->email],
            ['Grant' => OAuth2Grants::CLIENT_CREDENTIALS],
        );
        $io->warning('Store the secret now — it is shown once and cannot be recovered later.');

        return self::SUCCESS;
    }
}
