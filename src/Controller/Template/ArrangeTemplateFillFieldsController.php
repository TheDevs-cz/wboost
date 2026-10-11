<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Template;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Template;
use WBoost\Web\Entity\User;
use WBoost\Web\Message\Template\ArrangeTemplateFillFields;

/**
 * Saves the "Všechna pole" panel order an ADMIN dragged into place on a fill
 * page (single variant or group — the group is 1:1 with its template).
 * Body: `{"order": ["<inputId>", …]}`.
 */
final class ArrangeTemplateFillFieldsController extends AbstractController
{
    private const int MAX_FIELDS = 500;

    public function __construct(
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/template/{templateId}/fill-field-order', name: 'template_fill_field_order', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    public function __invoke(
        #[MapEntity(id: 'templateId')]
        Template $template,
        Request $request,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $order = is_array($data) ? ($data['order'] ?? null) : null;

        if (!is_array($order) || !array_is_list($order) || count($order) > self::MAX_FIELDS) {
            throw new BadRequestHttpException('Expected {"order": [inputId, …]}.');
        }

        $inputIds = [];
        foreach ($order as $inputId) {
            if (!is_string($inputId) || $inputId === '' || strlen($inputId) > 64) {
                throw new BadRequestHttpException('Every order entry must be an inputId string.');
            }
            $inputIds[$inputId] = $inputId;
        }

        $this->bus->dispatch(new ArrangeTemplateFillFields($template->id, array_values($inputIds)));

        return new JsonResponse(['status' => 'success']);
    }
}
