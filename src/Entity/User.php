<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use WBoost\Web\Mcp\Security\McpScope;

#[ORM\Entity]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const string ROLE_DESIGNER = 'ROLE_DESIGNER';
    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    /** Browse, fill and download — designing and gallery uploads need an admin. */
    public const array DEFAULT_MCP_SCOPES = ['templates:read', 'templates:export'];

    /** {@see DEFAULT_MCP_SCOPES} as the column default — keep the two in sync. */
    private const string DEFAULT_MCP_SCOPES_JSON = '["templates:read","templates:export"]';

    /** @var array<string> */
    #[Column(type: Types::JSON)]
    private array $roles = [];

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column]
    public string $password = '';

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $name = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $avatar = null;

    /**
     * Last time the user was seen making an interactive request. Written by
     * {@see \WBoost\Web\Services\Usage\UserActivityListener} (via raw DBAL, so
     * no ORM mutator here); read for the once-per-minute activity throttle and
     * the admin usage report.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $lastActivityAt = null;

    /**
     * The admin-set CEILING on what an AI client this user connects through
     * OAuth may be granted — raw {@see McpScope} values, read through
     * {@see allowedMcpScopes()}. The consent screen and the issued tokens are
     * capped to it, and so is every MCP request made with such a token, so
     * narrowing it takes effect on the next call. Personal access tokens are
     * NOT capped by it: their scopes are already chosen by the admin who mints
     * them (`app:mcp:token:create --scopes`).
     *
     * @var list<string>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['default' => self::DEFAULT_MCP_SCOPES_JSON])]
    public array $mcpScopes = self::DEFAULT_MCP_SCOPES;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Column(length: 180, unique: true)]
        readonly public string $email,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public DateTimeImmutable $registeredAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => true])]
        public bool $confirmed = true,
    ) {
    }

    public function changePassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function confirm(): void
    {
        $this->confirmed = true;
    }

    /**
     * @param list<string> $roles
     */
    public function changeRoles(array $roles): void
    {
        $this->roles = array_values(array_unique($roles));
    }

    public function getUserIdentifier(): string
    {
        /** @var non-empty-string $email */
        $email = $this->email;

        return $email;
    }

    /**
     * @return array<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function getPassword(): string
    {
        // Just to satisfy the interface ...
        return $this->password;
    }

    /**
     * @param list<string> $scopes raw values; anything that is not an MCP scope is dropped
     */
    public function changeMcpScopes(array $scopes): void
    {
        $this->mcpScopes = array_map(
            static fn (McpScope $scope): string => $scope->value,
            McpScope::fromStrings($scopes),
        );
    }

    /**
     * The ceiling EXPANDED by implication ({@see McpScope::grants()}): allowing
     * `templates:export` allows the `templates:read` it implies, so a client
     * asking for read alone is not refused by a user set up for export.
     *
     * @return list<McpScope>
     */
    public function allowedMcpScopes(): array
    {
        $allowed = [];

        foreach (McpScope::fromStrings($this->mcpScopes) as $scope) {
            foreach ($scope->grants() as $granted) {
                if (in_array($granted, $allowed, true) === false) {
                    $allowed[] = $granted;
                }
            }
        }

        return $allowed;
    }

    public function getDisplayName(): string
    {
        return $this->name ?? $this->email;
    }

    public function editProfile(
        null|string $name,
    ): void {
        $this->name = $name;
    }
}
