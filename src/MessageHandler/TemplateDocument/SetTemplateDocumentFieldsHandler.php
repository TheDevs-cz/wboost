<?php

declare(strict_types=1);

namespace WBoost\Web\MessageHandler\TemplateDocument;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use WBoost\Web\Entity\TemplateDocumentField;
use WBoost\Web\Exceptions\InvalidTemplateDocumentChange;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Message\TemplateDocument\SetTemplateDocumentFields;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Services\TemplateDocument\TemplateDocumentRules;

#[AsMessageHandler]
readonly final class SetTemplateDocumentFieldsHandler
{
    public function __construct(
        private TemplateDocumentRepository $documentRepository,
        private TemplateDocumentRules $rules,
    ) {
    }

    /**
     * @throws TemplateDocumentNotFound
     * @throws InvalidTemplateDocumentChange
     */
    public function __invoke(SetTemplateDocumentFields $message): void
    {
        $document = $this->documentRepository->get($message->documentId);

        foreach ($message->fields as $definition) {
            if (trim($definition->name) === '') {
                throw InvalidTemplateDocumentChange::invalidBinding('Společné pole musí mít název.');
            }

            $this->rules->assertBindings($document, $definition->name, $definition->kind, $definition->bindings);
        }

        $wanted = [];
        foreach ($message->fields as $position => $definition) {
            $wanted[$definition->id->toString()] = true;
            $field = $document->field($definition->id);

            if ($field !== null) {
                $field->edit(trim($definition->name), $definition->kind, $position, $definition->bindings);
                continue;
            }

            $document->addField(new TemplateDocumentField(
                $definition->id,
                $document,
                trim($definition->name),
                $definition->kind,
                $position,
                $definition->bindings,
            ));
        }

        foreach ($document->fields() as $field) {
            if (!isset($wanted[$field->id->toString()])) {
                $document->removeField($field);
            }
        }
    }
}
