<?php

declare(strict_types=1);

namespace Pim\Component\Catalog\EntityWithFamilyVariant;

use Pim\Component\Catalog\Model\ProductModelInterface;

/**
 * Iterates over all the list of product models and make sure we keep only the values set in the family variant in which
 * they belong.
 *
 * @author    Samir Boulil <samir.boulil@akeneo.com>
 * @copyright 2018 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class KeepOnlyValuesForProductModelsTrees
{
    /** @var KeepOnlyValuesForVariation */
    private $keepOnlyValuesForVariation;

    /**
     * @param KeepOnlyValuesForVariation $keepOnlyValuesForVariation
     */
    public function __construct(KeepOnlyValuesForVariation $keepOnlyValuesForVariation) {

        $this->keepOnlyValuesForVariation = $keepOnlyValuesForVariation;
    }

    /**
     * @param array $entitiesWithFamilyVariant
     */
    public function update(array $entitiesWithFamilyVariant): void
    {
        $this->keepOnlyValuesForVariation->updateEntitiesWithFamilyVariant([$entitiesWithFamilyVariant]);
        foreach ($entitiesWithFamilyVariant as $entityWithValue) {
            if (!$entityWithValue instanceof ProductModelInterface) {
                break;
            }
            $entityWithValuesChildren = [];
            if ($entityWithValue->hasProductModels()) {
                $entityWithValuesChildren = $entityWithValue->getProductModels()->toArray();
            } elseif (!$entityWithValue->getProducts()->isEmpty()) {
                $entityWithValuesChildren = $entityWithValue->getProducts()->toArray();
            }
            $this->update($entityWithValuesChildren);
        }
    }
}
