<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * One page of a {@see TemplateDocument}: a template + the variant of the
 * document's size it renders. The template column is UNIQUE — a template is a
 * page of at most one document. Deleting the template or the variant CASCADEs
 * the row away, which simply returns the template to the listing (deleting a
 * page's variant is refused upstream so that never happens silently).
 */
#[Entity]
#[Index(name: 'idx_template_document_page_document', columns: ['document_id'])]
class TemplateDocumentPage
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne(inversedBy: 'pages')]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public TemplateDocument $document,

        #[ManyToOne]
        #[JoinColumn(nullable: false, unique: true, onDelete: "CASCADE")]
        readonly public Template $template,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public TemplateVariant $variant,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public int $position,
    ) {
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }
}
