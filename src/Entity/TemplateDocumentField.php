<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\DocumentFieldBinding;
use WBoost\Web\Value\DocumentFieldKind;

/**
 * A shared field ("Společné pole"): ONE value the user types / picks once,
 * filled into every bound input across the document's pages. The ONLY way
 * pages share a value — pages never share implicitly, not even when two of
 * them carry the same inputId (a duplicated template copies its canvas
 * verbatim). At most one binding per page.
 */
#[Entity]
class TemplateDocumentField
{
    /**
     * @var list<array{templateId: string, inputId: string}>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $bindings = [];

    /**
     * @param list<DocumentFieldBinding> $bindings
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(inversedBy: 'fields')]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public TemplateDocument $document,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 8, enumType: DocumentFieldKind::class)]
        public DocumentFieldKind $kind,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $position,

        array $bindings = [],
    ) {
        $this->bindTo($bindings);
    }

    /**
     * @param list<DocumentFieldBinding> $bindings
     */
    public function edit(string $name, DocumentFieldKind $kind, int $position, array $bindings): void
    {
        $this->name = $name;
        $this->kind = $kind;
        $this->position = $position;
        $this->bindTo($bindings);
    }

    /**
     * @return list<DocumentFieldBinding>
     */
    public function bindings(): array
    {
        $result = [];

        foreach ($this->bindings as $data) {
            $binding = DocumentFieldBinding::fromArray($data);

            if ($binding !== null) {
                $result[] = $binding;
            }
        }

        return $result;
    }

    public function bindingForTemplate(string $templateId): null|DocumentFieldBinding
    {
        foreach ($this->bindings() as $binding) {
            if ($binding->templateId === $templateId) {
                return $binding;
            }
        }

        return null;
    }

    public function unbindTemplate(string $templateId): void
    {
        $this->bindTo(array_values(array_filter(
            $this->bindings(),
            static fn (DocumentFieldBinding $binding): bool => $binding->templateId !== $templateId,
        )));
    }

    /**
     * @param list<DocumentFieldBinding> $bindings
     */
    private function bindTo(array $bindings): void
    {
        $this->bindings = array_map(static fn (DocumentFieldBinding $binding): array => $binding->toArray(), $bindings);
    }
}
