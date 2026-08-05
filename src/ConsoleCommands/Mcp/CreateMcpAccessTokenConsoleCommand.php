<?php

declare(strict_types=1);

namespace WBoost\Web\ConsoleCommands\Mcp;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use WBoost\Web\Exceptions\UserNotFound;
use WBoost\Web\Mcp\Security\McpScope;
use WBoost\Web\Mcp\Security\McpTokenGenerator;
use WBoost\Web\Message\Mcp\CreateMcpAccessToken;
use WBoost\Web\Repository\UserRepository;
use WBoost\Web\Services\ProvideIdentity;

/**
 * Issues a personal access token for the MCP server at `/_mcp`, on behalf of
 * one existing user. Mirrors `app:oauth-client:create`: resolve the user by
 * e-mail, mint the credential, print it once with a warning.
 */
#[AsCommand('app:mcp:token:create', 'Create an MCP personal access token for a user (the secret is shown once)')]
final class CreateMcpAccessTokenConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private UserRepository $userRepository,
        readonly private McpTokenGenerator $tokenGenerator,
        readonly private ProvideIdentity $provideIdentity,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user-email', InputArgument::REQUIRED, 'Email of the user the token acts as')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Human-readable label shown in app:mcp:token:list', 'MCP client')
            ->addOption(
                'scopes',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf('Comma-separated scopes (%s)', implode(', ', self::validScopes())),
                McpScope::TemplatesRead->value,
            )
            ->setHelp(sprintf(
                <<<'HELP'
                    Creates a personal access token that lets an MCP client (Claude Code,
                    claude.ai, ChatGPT) act as the given user against /_mcp.

                    A token can only NARROW what that user may do, never widen it: the
                    ordinary voters still decide access and the scopes intersect with them.

                      <info>--scopes</info> is comma-separated, e.g. <comment>--scopes=templates:read,templates:export</comment>
                      Valid values: <comment>%s</comment>
                      <comment>templates:design</comment> and <comment>templates:export</comment> each imply <comment>templates:read</comment>, so
                      there is no need to list it alongside them.
                      Omitted, it defaults to the read-only <comment>%s</comment>.

                    The secret is printed ONCE. Only its sha256 is stored, so a lost token is
                    replaced (create a new one, revoke the old) — never recovered.
                    HELP,
                implode(', ', self::validScopes()),
                McpScope::TemplatesRead->value,
            ));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('user-email');

        /** @var string $name */
        $name = $input->getOption('name');

        /** @var string $rawScopes */
        $rawScopes = $input->getOption('scopes');

        try {
            $user = $this->userRepository->get($email);
        } catch (UserNotFound) {
            $io->error(sprintf('User with email "%s" was not found.', $email));

            return self::FAILURE;
        }

        /** @var list<string> $scopes */
        $scopes = [];

        foreach (explode(',', $rawScopes) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            // Strict on purpose: McpScope::fromStrings() tolerates unknown
            // values because a STORED row must never break authentication, but
            // a human typing --scopes=templates:reed has made a mistake and
            // silently issuing a token with fewer scopes than asked for is the
            // worst possible answer.
            $scope = McpScope::tryFrom($candidate);

            if ($scope === null) {
                $io->error(sprintf(
                    'Unknown scope "%s". Valid scopes: %s.',
                    $candidate,
                    implode(', ', self::validScopes()),
                ));

                return self::FAILURE;
            }

            if (in_array($scope->value, $scopes, true)) {
                continue;
            }

            $scopes[] = $scope->value;
        }

        if ($scopes === []) {
            $io->error(sprintf('At least one scope is required. Valid scopes: %s.', implode(', ', self::validScopes())));

            return self::FAILURE;
        }

        $tokenId = $this->provideIdentity->next();
        $plainTextToken = $this->tokenGenerator->generate();

        $this->messageBus->dispatch(
            new CreateMcpAccessToken(
                $tokenId,
                $user->id,
                $name,
                $scopes,
                $plainTextToken,
            ),
        );

        $io->success('MCP access token created.');
        $io->definitionList(
            ['Token ID' => $tokenId->toString()],
            ['Name' => $name],
            ['User' => $user->email],
            ['Scopes' => implode(', ', $scopes)],
        );

        $io->writeln('Access token:');
        $io->newLine();
        // Written raw rather than through a styled block or the definition list
        // above: those wrap at the terminal width, and a token broken across
        // two lines is a token nobody can copy-paste.
        $io->writeln($plainTextToken);
        $io->newLine();

        $io->warning('Store the token now — only its hash is kept, so it cannot be shown again. A lost token is replaced, never recovered.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private static function validScopes(): array
    {
        return array_map(static fn (McpScope $scope): string => $scope->value, McpScope::cases());
    }
}
