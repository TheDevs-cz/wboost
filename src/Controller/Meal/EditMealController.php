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
use WBoost\Web\Entity\Diet;
use WBoost\Web\Entity\Meal;
use WBoost\Web\FormData\MealFormData;
use WBoost\Web\FormData\MealVariantFormData;
use WBoost\Web\FormType\MealFormType;
use WBoost\Web\Message\Meal\EditMeal;
use WBoost\Web\Query\GetDiets;
use WBoost\Web\Query\GetDishTypes;
use WBoost\Web\Query\GetMeals;
use WBoost\Web\Services\ProvideIdentity;
use WBoost\Web\Services\Security\MealVoter;

final class EditMealController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $bus,
        readonly private GetDishTypes $getDishTypes,
        readonly private GetDiets $getDiets,
        readonly private GetMeals $getMeals,
        readonly private ProvideIdentity $provideIdentity,
    ) {
    }

    #[Route(path: '/meal/{mealId}/edit', name: 'edit_meal')]
    #[IsGranted(MealVoter::EDIT, 'meal')]
    public function __invoke(
        Request $request,
        #[MapEntity(id: 'mealId')]
        Meal $meal,
    ): Response {
        $data = new MealFormData();
        $data->name = $meal->name;
        $data->internalName = $meal->internalName;
        $data->mealType = $meal->mealType;
        $data->dishTypeId = $meal->dishType->id;
        $data->dietIds = array_map(fn(Diet $d) => $d->id, $meal->diets());
        $data->energyValue = $meal->energyValue;
        $data->fats = $meal->fats;
        $data->carbohydrates = $meal->carbohydrates;
        $data->proteins = $meal->proteins;

        // Populate existing variants
        foreach ($meal->variants() as $variant) {
            $variantData = new MealVariantFormData();
            $variantData->id = $variant->id;

            if ($variant->isReferenceMode()) {
                $variantData->mode = MealVariantFormData::MODE_REFERENCE;
                $variantData->referenceMealId = $variant->referenceMeal?->id;
            } else {
                $variantData->mode = MealVariantFormData::MODE_MANUAL;
                $variantData->dietIds = array_map(fn(Diet $d) => $d->id, $variant->diets());
                $variantData->name = $variant->name ?? '';
                $variantData->energyValue = $variant->energyValue;
                $variantData->fats = $variant->fats;
                $variantData->carbohydrates = $variant->carbohydrates;
                $variantData->proteins = $variant->proteins;
            }

            $data->variants->add($variantData);
        }

        $dishTypes = $this->getDishTypes->allForProject($meal->project->id);
        $diets = $this->getDiets->allForProject($meal->project->id);
        $allMeals = $this->getMeals->allForProject($meal->project->id);
        // Filter out current meal (can't reference itself)
        $meals = array_filter($allMeals, fn(Meal $m) => !$m->id->equals($meal->id));

        $form = $this->createForm(MealFormType::class, $data, [
            'dish_types' => $dishTypes,
            'diets' => $diets,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $variants = [];
            foreach ($data->variants as $variantData) {
                $variants[] = [
                    'id' => $variantData->id ?? $this->provideIdentity->next(),
                    'mode' => $variantData->mode,
                    'name' => $variantData->name,
                    'dietIds' => $variantData->dietIds,
                    'referenceMealId' => $variantData->referenceMealId,
                    'energyValue' => $variantData->energyValue,
                    'fats' => $variantData->fats,
                    'carbohydrates' => $variantData->carbohydrates,
                    'proteins' => $variantData->proteins,
                ];
            }

            $this->bus->dispatch(
                new EditMeal(
                    $meal->id,
                    $data->mealType,
                    $data->dishTypeId,
                    $data->name,
                    $data->internalName,
                    $data->dietIds,
                    $data->energyValue,
                    $data->fats,
                    $data->carbohydrates,
                    $data->proteins,
                    $variants,
                ),
            );

            $this->addFlash('success', 'Jídlo bylo upraveno.');

            return $this->redirectToRoute('meals', [
                'projectId' => $meal->project->id,
            ]);
        }

        return $this->render('edit_meal.html.twig', [
            'form' => $form,
            'meal' => $meal,
            'project' => $meal->project,
            'meals' => $meals,
            'diets' => $diets,
        ]);
    }
}
