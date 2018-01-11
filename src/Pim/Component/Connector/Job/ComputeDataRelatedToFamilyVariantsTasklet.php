<?php

declare(strict_types=1);

namespace Pim\Component\Connector\Job;

use Akeneo\Component\Batch\Item\InitializableInterface;
use Akeneo\Component\Batch\Item\InvalidItemException;
use Akeneo\Component\Batch\Item\ItemReaderInterface;
use Akeneo\Component\Batch\Model\StepExecution;
use Akeneo\Component\Batch\Step\StepExecutionAwareInterface;
use Akeneo\Component\StorageUtils\Cache\CacheClearerInterface;
use Akeneo\Component\StorageUtils\Cursor\CursorInterface;
use Akeneo\Component\StorageUtils\Saver\BulkSaverInterface;
use Akeneo\Component\StorageUtils\Saver\SaverInterface;
use Pim\Component\Catalog\Model\FamilyInterface;
use Pim\Component\Catalog\Query\Filter\Operators;
use Pim\Component\Catalog\Query\ProductQueryBuilderFactoryInterface;
use Pim\Component\Catalog\Repository\FamilyRepositoryInterface;
use Pim\Component\Catalog\Repository\ProductModelRepositoryInterface;
use Pim\Component\Connector\Step\TaskletInterface;

/**
 * Foreach line of the file to import we will:
 * - fetch the corresponding family object
 * - fetch all the root product models of this family
 * - save this root product model and all its descendants (in order to such things as recompute completeness for instance)
 *
 * @author    Samir Boulil <samir.boulil@akeneo.com>
 * @copyright 2018 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class ComputeDataRelatedToFamilyVariantsTasklet implements TaskletInterface, InitializableInterface
{
    private const BULK_SIZE = 100;

    /** @var StepExecution */
    private $stepExecution;

    /** @var ItemReaderInterface */
    private $familyReader;

    /** @var FamilyRepositoryInterface */
    private $familyRepository;

    /** @var ProductModelRepositoryInterface */
    private $productModelRepository;

    /** @var BulkSaverInterface */
    private $productModelSaver;

    /** @var SaverInterface */
    private $productModelDescendantsSaver;

    /** @var CacheClearerInterface */
    private $cacheClearer;

    /** @var ProductQueryBuilderFactoryInterface */
    private $productQueryBuilderFactory;

    /**
     * @param FamilyRepositoryInterface       $familyRepository
     * @param ProductModelRepositoryInterface $productModelRepository
     * @param ItemReaderInterface             $familyReader
     * @param BulkSaverInterface              $productModelSaver
     * @param SaverInterface                  $productModelDescendantsSaver
     * @param CacheClearerInterface           $cacheClearer
     */
    public function __construct(
        FamilyRepositoryInterface $familyRepository,
        ProductQueryBuilderFactoryInterface $productQueryBuilderFactory,
        ItemReaderInterface $familyReader,
        BulkSaverInterface $productModelSaver,
        SaverInterface $productModelDescendantsSaver,
        CacheClearerInterface $cacheClearer
    ) {
        $this->familyReader = $familyReader;
        $this->familyRepository = $familyRepository;
        $this->productQueryBuilderFactory = $productQueryBuilderFactory;
        $this->productModelSaver = $productModelSaver;
        $this->productModelDescendantsSaver = $productModelDescendantsSaver;
        $this->cacheClearer = $cacheClearer;
    }

    /**
     * @param StepExecution $stepExecution
     */
    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
    }

    /**
     * Execute the tasklet
     */
    public function execute()
    {
        $this->initialize();

        while (true) {
            try {
                $familyItem = $this->familyReader->read();
                if (null === $familyItem) {
                    break;
                }
            } catch (InvalidItemException $e) {
                continue;
            }

            $family = $this->familyRepository->findOneByIdentifier($familyItem['code']);
            if (null === $family) {
                $this->stepExecution->incrementSummaryInfo('skip');
                continue;
            }

            $rootProductModels = $this->getRootProductModelsForFamily($family);
            $this->computeProductModelAndProductModelDescendantsInBulk($rootProductModels);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function initialize()
    {
        if ($this->familyReader instanceof StepExecutionAwareInterface) {
            $this->familyReader->setStepExecution($this->stepExecution);
        }
        $this->cacheClearer->clear();
    }

    /**
     * @param FamilyInterface $family
     *
     * @return CursorInterface
     */
    private function getRootProductModelsForFamily(FamilyInterface $family): CursorInterface
    {
        $pqb = $this->productQueryBuilderFactory->create();
        $pqb->addFilter('family', Operators::EQUALS, $family->getCode());
        $pqb->addFilter('parent', Operators::IS_EMPTY, null);

        return $pqb->execute();
    }

    /**
     * @param array $rootProductModels
     */
    private function computeProductModelAndProductModelDescendantsInBulk(CursorInterface $rootProductModels): void
    {
        $rootProductModelBulk = [];
        foreach ($rootProductModels as $rootProductModel) {
            $rootProductModelBulk[] = $rootProductModel;

            if (\count($rootProductModelBulk) === self::BULK_SIZE) {
                $this->productModelSaver->saveAll($rootProductModelBulk);
                foreach ($rootProductModelBulk as $productModel) {
                    $this->productModelDescendantsSaver->save($productModel);
                    $this->stepExecution->incrementSummaryInfo('process');
                }
                $rootProductModelBulk = [];
            }
        }

        $this->productModelSaver->saveAll($rootProductModelBulk);
        foreach ($rootProductModelBulk as $productModel) {
            $this->productModelDescendantsSaver->save($productModel);
            $this->stepExecution->incrementSummaryInfo('process');
        }
    }
}
