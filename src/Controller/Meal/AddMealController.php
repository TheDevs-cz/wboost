<?php

declare(strict_types=1);

namespace WBoost\Web\Controller\Meal;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use WBoost\Web\Entity\Project;
use WBoost\Web\FormData\MealFormData;
use WBoost\Web\FormType\MealFormType;
use WBoost\Web\Message\Meal\AddMeal;
use WBoost\Web\Query\GetDiets;
use WBoost\Web\Query\GetDishTypes;
use WBoost\Web\Query\GetMeals;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\ProjectVoter;

final class AddMealController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private ProvideIdentity $provideIdentity,
        readonly private GetDishTypes $getDishTypes,
        readonly private GetDiets $getDiets,
        readonly private GetMeals $getMeals,
    ) {
    }

    #[Route(path: '/project/{projectId}/meals/add', name: 'add_meal')]
    #[IsGranted(ProjectVoter::EDIT, 'project')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'projectId')]
        Project $project,
    ): Response {
        $data = new MealFormData();
        $dishTypes = $this->getDishTypes->allForProject($project->id);
        $diets = $this->getDiets->allForProject($project->id);
        $meals = $this->getMeals->allForProject($project->id);

        $form = $this->createForm(MealFormType::class, $data, [
            'dish_types' => $dishTypes,
            'diets' => $diets,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mealId = $this->provideIdentity->next();

            assert($data->mealType !== null);
            assert($data->dishTypeId !== null);

            $variants = [];
            foreach ($data->variants as $variantData) {
                $variants[] = [
                    'id' => $variantData->id ?? $this->provideIdentity->next(),
                    'mode' => $variantData->mode,
                    'name' => $variantData->name,
                    'dietId' => $variantData->dietId,
                    'referenceMealId' => $variantData->referenceMealId,
                    'energyValue' => $variantData->energyValue,
                    'fats' => $variantData->fats,
                    'carbohydrates' => $variantData->carbohydrates,
                    'proteins' => $variantData->proteins,
                ];
            }

            $this->bus->dispatch(
                new AddMeal(
                    $project->id,
                    $mealId,
                    $data->mealType,
                    $data->dishTypeId,
                    $data->name,
                    $data->internalName,
                    $data->dietId,
                    $data->energyValue,
                    $data->fats,
                    $data->carbohydrates,
                    $data->proteins,
                    $variants,
                ),
            );

            $this->addFlash('success', 'Jídlo bylo vytvořeno.');

            return $this->redirectToRoute('meals', [
                'projectId' => $project->id,
            ]);
        }

        return $this->render('add_meal.html.twig', [
            'form' => $form,
            'project' => $project,
            'meals' => $meals,
            'diets' => $diets,
        ]);
    }
}
