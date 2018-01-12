<?php

namespace spec\Pim\Component\Catalog\EntityWithFamilyVariant;

use Doctrine\Common\Collections\ArrayCollection;
use PhpSpec\ObjectBehavior;
use Pim\Component\Catalog\EntityWithFamilyVariant\KeepOnlyValuesForProductModelsTrees;
use Pim\Component\Catalog\EntityWithFamilyVariant\KeepOnlyValuesForVariation;
use Pim\Component\Catalog\Model\ProductInterface;
use Pim\Component\Catalog\Model\ProductModelInterface;

class KeepOnlyValuesForProductModelsTreesSpec extends ObjectBehavior
{
    public function let(KeepOnlyValuesForVariation $keepOnlyValuesForVariation)
    {
        $this->beConstructedWith($keepOnlyValuesForVariation);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(KeepOnlyValuesForProductModelsTrees::class);
    }

    function it_iterates_over_the_product_model_and_its_children_and_keeps_only_values_for_variation(
        $keepOnlyValuesForVariation,
        ProductModelInterface $rootProductModel,
        ArrayCollection $subProductModels,
        ProductModelInterface $subProductModel1,
        ProductModelInterface $subProductModel2,
        ArrayCollection $products1,
        ProductInterface $product1,
        ProductInterface $product2,
        ArrayCollection $products2,
        ProductInterface $product3,
        ProductInterface $product4
    ) {
        $rootProductModel->hasProductModels()->willReturn(true);
        $rootProductModel->getProductModels()->willReturn($subProductModels);

        $subProductModels->toArray()->willReturn([$subProductModel1, $subProductModel2]);
        $subProductModel1->hasProductModels()->willReturn(false);
        $subProductModel1->getProducts()->willReturn($products1);

        $subProductModel2->hasProductModels()->willReturn(false);
        $subProductModel2->getProducts()->willReturn($products2);

        $products1->isEmpty()->willReturn(false);
        $products1->toArray()->willReturn([$product1, $product2]);

        $products2->isEmpty()->willReturn(false);
        $products2->toArray()->willReturn([$product3, $product4]);

        $keepOnlyValuesForVariation->updateEntitiesWithFamilyVariant([$rootProductModel]);
        $keepOnlyValuesForVariation->updateEntitiesWithFamilyVariant([$subProductModel1, $subProductModel2]);
        $keepOnlyValuesForVariation->updateEntitiesWithFamilyVariant([$product1, $product2, $product3, $product4]);

        $this->update([$rootProductModel]);
    }
}
