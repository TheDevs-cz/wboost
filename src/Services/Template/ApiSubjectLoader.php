<?php

declare(strict_types=1);

namespace WBoost\Web\Services\Template;

use Ramsey\Uuid\Uuid;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use WBoost\Web\Entity\TemplateDocument;
use WBoost\Web\Entity\TemplateDocumentPage;
use WBoost\Web\Entity\TemplateGroup;
use WBoost\Web\Entity\TemplateVariant;
use WBoost\Web\Exceptions\TemplateDocumentNotFound;
use WBoost\Web\Exceptions\TemplateGroupNotFound;
use WBoost\Web\Exceptions\TemplateVariantNotFound;
use WBoost\Web\Query\GetTemplateGroupMembers;
use WBoost\Web\Repository\TemplateDocumentRepository;
use WBoost\Web\Repository\TemplateGroupRepository;
use WBoost\Web\Repository\TemplateVariantRepository;
use WBoost\Web\Services\Security\TemplateDocumentVoter;
use WBoost\Web\Services\Security\TemplateGroupVoter;
use WBoost\Web\Services\Security\TemplateVariantVoter;

/**
 * Resolves the route ids of the API's fill surfaces (a variant, a template
 * group, a group's member dimension) to entities the caller may VIEW — one
 * place for the 400 / 404 / 403 ladder every render + history operation
 * shares.
 */
readonly final class ApiSubjectLoader
{
    public function __construct(
        private Security $security,
        private TemplateVariantRepository $variantRepository,
        private TemplateGroupRepository $groupRepository,
        private GetTemplateGroupMembers $groupMembers,
        private TemplateDocumentRepository $documentRepository,
    ) {
    }

    /**
     * A multi-page document the caller may VIEW (fill, preview, export).
     * 404 while documents are not rolled out to the caller.
     */
    public function document(mixed $documentId): TemplateDocument
    {
        return $this->loadDocument($documentId, TemplateDocumentVoter::VIEW);
    }

    /**
     * A multi-page document the caller may EDIT (pages, shared fields,
     * rename, dissolve) — designer + project owner, like the web.
     */
    public function writableDocument(mixed $documentId): TemplateDocument
    {
        return $this->loadDocument($documentId, TemplateDocumentVoter::EDIT);
    }

    /**
     * A page of the document by its page id (also accepts the page's
     * template id). 404 for a page of another document.
     */
    public function documentPage(TemplateDocument $document, mixed $pageId): TemplateDocumentPage
    {
        if (!is_string($pageId) || !Uuid::isValid($pageId)) {
            throw new BadRequestHttpException('Invalid page id.');
        }

        $uuid = Uuid::fromString($pageId);
        $page = $document->page($uuid) ?? $document->pageOfTemplate($uuid);

        if ($page === null) {
            throw new NotFoundHttpException('Page does not belong to this document.');
        }

        return $page;
    }

    private function loadDocument(mixed $documentId, string $attribute): TemplateDocument
    {

        if (!is_string($documentId) || !Uuid::isValid($documentId)) {
            throw new BadRequestHttpException('Invalid document id.');
        }

        try {
            $document = $this->documentRepository->get(Uuid::fromString($documentId));
        } catch (TemplateDocumentNotFound) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(TemplateDocumentVoter::VIEW, $document)) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted($attribute, $document)) {
            throw new AccessDeniedHttpException();
        }

        return $document;
    }

    public function variant(mixed $variantId): TemplateVariant
    {
        if (!is_string($variantId) || !Uuid::isValid($variantId)) {
            throw new BadRequestHttpException('Invalid variant id.');
        }

        try {
            $variant = $this->variantRepository->get(Uuid::fromString($variantId));
        } catch (TemplateVariantNotFound) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(TemplateVariantVoter::VIEW, $variant)) {
            throw new AccessDeniedHttpException();
        }

        return $variant;
    }

    public function group(mixed $groupId): TemplateGroup
    {
        if (!is_string($groupId) || !Uuid::isValid($groupId)) {
            throw new BadRequestHttpException('Invalid group id.');
        }

        try {
            $group = $this->groupRepository->get(Uuid::fromString($groupId));
        } catch (TemplateGroupNotFound) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(TemplateGroupVoter::VIEW, $group)) {
            throw new AccessDeniedHttpException();
        }

        return $group;
    }

    /**
     * The group's member dimensions in display order (presets first). Only
     * variants carrying the group FK are members — a variant added by hand to
     * a grouped template is not part of the group fill.
     *
     * @return list<TemplateVariant>
     */
    public function members(TemplateGroup $group): array
    {
        return $this->groupMembers->variants($group->id);
    }

    public function member(TemplateGroup $group, mixed $variantId): TemplateVariant
    {
        if (!is_string($variantId) || !Uuid::isValid($variantId)) {
            throw new BadRequestHttpException('Invalid variant id.');
        }

        foreach ($this->members($group) as $variant) {
            if ($variant->id->toString() === strtolower($variantId)) {
                return $variant;
            }
        }

        throw new NotFoundHttpException('Variant does not belong to this group.');
    }
}
