<?php

declare(strict_types=1);

namespace WBoost\Web\Value;

use Ramsey\Uuid\UuidInterface;

/**
 * One shared field as an editor (web form / API / MCP) submits it.
 */
final readonly class DocumentFieldDefinition
{
    /**
     * @param list<DocumentFieldBinding> $bindings
     */
    public function __construct(
        public UuidInterface $id,
        public string $name,
        public DocumentFieldKind $kind,
        public array $bindings,
    ) {
    }
}
