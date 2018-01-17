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
     * It is important to validate and save the product model tree upward. Starting from the products up to the root
     * product model otherwise we may loose information when moving attribute from attribute sets in the family variant.
     *
     * @param array $entitiesWithFamilyVariant
     */
    public function update(array $entitiesWithFamilyVariant): void
    {
        foreach ($entitiesWithFamilyVariant as $entityWithFamilyVariant) {
            if (!$entityWithFamilyVariant instanceof ProductModelInterface) {
                break;
            }

            if ($entityWithFamilyVariant->hasProductModels()) {
                $this->update($entityWithFamilyVariant->getProductModels()->toArray());
            } elseif (!$entityWithFamilyVariant->getProducts()->isEmpty()) {
                $this->update($entityWithFamilyVariant->getProducts()->toArray());
            }
        }

        $this->keepOnlyValuesForVariation->updateEntitiesWithFamilyVariant($entitiesWithFamilyVariant);
    }
}
