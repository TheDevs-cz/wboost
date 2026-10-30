<?php

declare(strict_types=1);

namespace WBoost\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embedded;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use WBoost\Web\Value\TemplateDimension;

/**
 * A multi-page document ("Dokument"): an ordered binder of ordinary templates.
 * Every page IS a {@see Template} (+ its variant of the document's size) — it
 * keeps its own editor, fill engine and renderer; the document only orders
 * them, links shared fields across them and exports them as ONE file.
 *
 * A template is a page of at most one document (UNIQUE on the page row) and is
 * hidden from the Šablony listing while attached; detaching returns it
 * untouched. See docs/architecture/templates-documents.md.
 */
#[Entity]
#[Index(name: 'idx_template_document_project', columns: ['project_id'])]
class TemplateDocument
{
    /** Export-time budget: every page is a render (two for the print PDF). */
    public const int MAX_PAGES = 30;

    /** @var Collection<int, TemplateDocumentPage> */
    #[Immutable]
    #[OneToMany(targetEntity: TemplateDocumentPage::class, mappedBy: 'document', cascade: ['persist'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $pages;

    /** @var Collection<int, TemplateDocumentField> */
    #[Immutable]
    #[OneToMany(targetEntity: TemplateDocumentField::class, mappedBy: 'document', cascade: ['persist'], orphanRemoval: true)]
    #[OrderBy(['position' => 'ASC'])]
    private Collection $fields;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: "CASCADE")]
        readonly public Project $project,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(onDelete: "SET NULL")]
        public null|TemplateCategory $category,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        readonly public \DateTimeImmutable $createdAt,

        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,

        /**
         * The size every page is rendered in (pages are matched on unit +
         * width + height) and the print setup new / attached pages get.
         */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Embedded(class: TemplateDimension::class, columnPrefix: 'dimension_')]
        public TemplateDimension $dimension,

        /** Shares the listing's sort space with the templates of its category. */
        #[Column(options: ['default' => 0])]
        public int $position,
    ) {
        $this->pages = new ArrayCollection();
        $this->fields = new ArrayCollection();
    }

    public function edit(null|TemplateCategory $category, string $name): void
    {
        $this->category = $category;
        $this->name = $name;
    }

    public function sort(int $position): void
    {
        $this->position = $position;
    }

    public function changePrintSettings(float $bleedMm, bool $cropMarks): void
    {
        $this->dimension = $this->dimension->withPrintSettings($bleedMm, $cropMarks);
    }

    /**
     * @return list<TemplateDocumentPage>
     */
    public function pages(): array
    {
        $pages = array_values($this->pages->toArray());
        usort($pages, static fn (TemplateDocumentPage $a, TemplateDocumentPage $b): int => $a->position <=> $b->position);

        return $pages;
    }

    public function pageOfTemplate(UuidInterface $templateId): null|TemplateDocumentPage
    {
        foreach ($this->pages as $page) {
            if ($page->template->id->equals($templateId)) {
                return $page;
            }
        }

        return null;
    }

    public function page(UuidInterface $pageId): null|TemplateDocumentPage
    {
        foreach ($this->pages as $page) {
            if ($page->id->equals($pageId)) {
                return $page;
            }
        }

        return null;
    }

    public function addPage(TemplateDocumentPage $page): void
    {
        $this->pages->add($page);
    }

    /**
     * Removes the page row only — the template stays (and reappears on the
     * listing). Its shared-field bindings go with it.
     */
    public function removePage(TemplateDocumentPage $page): void
    {
        $this->pages->removeElement($page);

        foreach ($this->fields as $field) {
            $field->unbindTemplate($page->template->id->toString());
        }
    }

    /**
     * @return list<TemplateDocumentField>
     */
    public function fields(): array
    {
        $fields = array_values($this->fields->toArray());
        usort($fields, static fn (TemplateDocumentField $a, TemplateDocumentField $b): int => $a->position <=> $b->position);

        return $fields;
    }

    public function field(UuidInterface $fieldId): null|TemplateDocumentField
    {
        foreach ($this->fields as $field) {
            if ($field->id->equals($fieldId)) {
                return $field;
            }
        }

        return null;
    }

    public function addField(TemplateDocumentField $field): void
    {
        $this->fields->add($field);
    }

    public function removeField(TemplateDocumentField $field): void
    {
        $this->fields->removeElement($field);
    }
}
