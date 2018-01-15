<?php

declare(strict_types=1);

namespace Pim\Component\Catalog\Job;

use Akeneo\Component\Batch\Model\StepExecution;
use Akeneo\Component\StorageUtils\Saver\SaverInterface;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityRepository;
use Pim\Component\Catalog\EntityWithFamilyVariant\KeepOnlyValuesForProductModelsTrees;
use Pim\Component\Catalog\Model\EntityWithFamilyVariantInterface;
use Pim\Component\Catalog\Model\ProductInterface;
use Pim\Component\Catalog\Model\ProductModelInterface;
use Pim\Component\Catalog\Repository\ProductModelRepositoryInterface;
use Pim\Component\Connector\Step\TaskletInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @author    Adrien Pétremann <adrien.petremann@akeneo.com>
 * @copyright 2017 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */
class ComputeFamilyVariantStructureChangesTasklet implements TaskletInterface
{
    /** @var StepExecution */
    private $stepExecution;

    /** @var EntityRepository */
    private $familyVariantRepository;

    /** @var ObjectRepository */
    private $variantProductRepository;

    /** @var ProductModelRepositoryInterface */
    private $productModelRepository;

    /** @var SaverInterface */
    private $productSaver;

    /** @var SaverInterface */
    private $productModelSaver;

    /** @var KeepOnlyValuesForProductModelsTrees */
    private $keepOnlyValuesForProductModelsTrees;

    /** @var ValidatorInterface */
    private $validator;

    /**
     * @param EntityRepository                    $familyVariantRepository
     * @param ObjectRepository                    $variantProductRepository
     * @param ProductModelRepositoryInterface     $productModelRepository
     * @param SaverInterface                      $productSaver
     * @param SaverInterface                      $productModelSaver
     * @param KeepOnlyValuesForProductModelsTrees $keepOnlyValuesForProductModelsTrees
     * @param ValidatorInterface                  $validator
     */
    public function __construct(
        EntityRepository $familyVariantRepository,
        ObjectRepository $variantProductRepository,
        ProductModelRepositoryInterface $productModelRepository,
        SaverInterface $productSaver,
        SaverInterface $productModelSaver,
        KeepOnlyValuesForProductModelsTrees $keepOnlyValuesForProductModelsTrees,
        ValidatorInterface $validator
    ) {
        $this->familyVariantRepository = $familyVariantRepository;
        $this->variantProductRepository = $variantProductRepository;
        $this->productModelRepository = $productModelRepository;
        $this->productSaver = $productSaver;
        $this->productModelSaver = $productModelSaver;
        $this->keepOnlyValuesForProductModelsTrees = $keepOnlyValuesForProductModelsTrees;
        $this->validator = $validator;
    }

    /**
     * {@inheritdoc}
     */
    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
    }

    /**
     * {@inheritdoc}
     */
    public function execute()
    {
        $jobParameters = $this->stepExecution->getJobParameters();
        $familyVariantCodes = $jobParameters->get('family_variant_codes');
        $familyVariants = $this->familyVariantRepository->findBy(['code' => $familyVariantCodes]);

        foreach ($familyVariants as $familyVariant) {
            $rootProductModels = $this->productModelRepository->findRootProductModels($familyVariant);
            foreach ($rootProductModels as $rootProductModel) {
                $this->keepOnlyValuesForProductModelsTrees->update([$rootProductModel]);
                $this->validateAndSaveVariantTree([$rootProductModel]);
            }
        }
    }

    /**
     * Recursively validates each elements of the tree and save them if they are valid.
     *
     * @param array $entitiesWithFamilyVariant
     */
    private function validateAndSaveVariantTree(array $entitiesWithFamilyVariant)
    {
        $updatedEntities = [];
        foreach ($entitiesWithFamilyVariant as $updatedEntity) {
            $this->validateEntity($updatedEntity);
            $updatedEntities[] = $updatedEntity;
        }

        foreach ($updatedEntities as $updatedEntity) {
            $this->saveEntity($updatedEntity);

            if (!$updatedEntity instanceof ProductModelInterface) {
                continue;
            }

            if ($updatedEntity->hasProductModels()) {
                $this->validateAndSaveVariantTree($updatedEntity->getProductModels()->toArray());
            } elseif (!$updatedEntity->getProducts()->isEmpty()) {
                $this->validateAndSaveVariantTree($updatedEntity->getProducts()->toArray());
            }
        }
    }

    /**
     * @param EntityWithFamilyVariantInterface $entityWithFamilyVariant
     */
    private function validateEntity(EntityWithFamilyVariantInterface $entityWithFamilyVariant): void
    {
        $violations = $this->validator->validate($entityWithFamilyVariant);

        if ($violations->count() > 0) {
            if ($entityWithFamilyVariant instanceof ProductModelInterface) {
                throw new \LogicException(
                    sprintf(
                        'Validation error for ProductModel with code "%s" during family variant structure change',
                        $entityWithFamilyVariant->getCode()
                    )
                );
            }
            if ($entityWithFamilyVariant instanceof ProductInterface) {
                throw new \LogicException(
                    sprintf(
                        'Validation error for Product with identifier "%s" during family variant structure change',
                        $entityWithFamilyVariant->getIdentifier()
                    )
                );
            }
        }
    }

    /**
     * @param EntityWithFamilyVariantInterface $entityWithFamilyVariant
     */
    private function saveEntity(EntityWithFamilyVariantInterface $entityWithFamilyVariant): void
    {
        if ($entityWithFamilyVariant instanceof ProductModelInterface) {
            $this->productModelSaver->save($entityWithFamilyVariant);
        } else {
            $this->productSaver->save($entityWithFamilyVariant);
        }
    }
}
