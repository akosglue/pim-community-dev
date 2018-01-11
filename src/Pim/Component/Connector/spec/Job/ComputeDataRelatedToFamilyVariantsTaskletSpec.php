<?php

declare(strict_types=1);

namespace spec\Pim\Component\Connector\Job;

use Akeneo\Component\Batch\Item\InvalidItemException;
use Akeneo\Component\Batch\Item\ItemReaderInterface;
use Akeneo\Component\Batch\Model\StepExecution;
use Akeneo\Component\StorageUtils\Cache\CacheClearerInterface;
use Akeneo\Component\StorageUtils\Cursor\CursorInterface;
use Akeneo\Component\StorageUtils\Saver\BulkSaverInterface;
use Akeneo\Component\StorageUtils\Saver\SaverInterface;
use PhpSpec\ObjectBehavior;
use Pim\Bundle\EnrichBundle\Elasticsearch\ProductAndProductModelQueryBuilderFactory;
use Pim\Bundle\EnrichBundle\ProductQueryBuilder\ProductAndProductModelQueryBuilder;
use Pim\Component\Catalog\Model\FamilyInterface;
use Pim\Component\Catalog\Model\ProductModelInterface;
use Pim\Component\Catalog\Query\Filter\Operators;
use Pim\Component\Catalog\Repository\FamilyRepositoryInterface;
use Pim\Component\Catalog\Repository\ProductModelRepositoryInterface;
use Pim\Component\Connector\Job\ComputeDataRelatedToFamilyVariantsTasklet;
use Prophecy\Argument;

class ComputeDataRelatedToFamilyVariantsTaskletSpec extends ObjectBehavior
{
    function let(
        FamilyRepositoryInterface $familyRepository,
        ProductAndProductModelQueryBuilderFactory $productAndProductModelQueryBuilderFactory,
        ItemReaderInterface $familyReader,
        BulkSaverInterface $productModelSaver,
        SaverInterface $productModelDescendantsSaver,
        CacheClearerInterface $cacheClearer
    ) {
        $this->beConstructedWith(
            $familyRepository,
            $productAndProductModelQueryBuilderFactory,
            $familyReader,
            $productModelSaver,
            $productModelDescendantsSaver,
            $cacheClearer
        );
    }

    function it_is_initializable()
    {
        $this->beAnInstanceOf(ComputeDataRelatedToFamilyVariantsTasklet::class);
    }

    function it_saves_the_product_model_and_its_descendants_belonging_to_the_family(
        $familyReader,
        $familyRepository,
        $productModelSaver,
        $productModelDescendantsSaver,
        $productAndProductModelQueryBuilderFactory,
        FamilyInterface $family,
        ProductModelInterface $rootProductModel,
        StepExecution $stepExecution,
        ProductAndProductModelQueryBuilder $pqb,
        CursorInterface $cursor
    ) {
        $familyReader->read()->willReturn(['code' => 'my_family'], null);
        $familyRepository->findOneByIdentifier('my_family')->willReturn($family);

        $family->getCode()->willReturn('family_code');

        $productAndProductModelQueryBuilderFactory->create()->willReturn($pqb);
        $pqb->addFilter('family', Operators::IN_LIST, ['family_code'])->shouldBeCalled();
        $pqb->addFilter('parent', Operators::IS_EMPTY, null)->shouldBeCalled();
        $pqb->execute()->willReturn($cursor);

        $cursor->rewind()->shouldBeCalled();
        $cursor->valid()->willReturn(true, false);
        $cursor->next()->willReturn($rootProductModel);
        $cursor->current()->willReturn($rootProductModel);

        $productModelSaver->saveAll([$rootProductModel])->shouldBeCalled();
        $productModelDescendantsSaver->save($rootProductModel)->shouldBeCalled();

        $stepExecution->incrementSummaryInfo('process')->shouldBeCalled();

        $this->setStepExecution($stepExecution);
        $this->execute();
    }

    function it_saves_the_product_models_and_its_descendants_belonging_to_the_families(
        $familyReader,
        $familyRepository,
        $productAndProductModelQueryBuilderFactory,
        $productModelSaver,
        $productModelDescendantsSaver,
        FamilyInterface $family1,
        FamilyInterface $family2,
        ProductModelInterface $rootProductModel1,
        ProductModelInterface $rootProductModel2,
        StepExecution $stepExecution,
        ProductAndProductModelQueryBuilder $pqb1,
        ProductAndProductModelQueryBuilder $pqb2,
        CursorInterface $cursor1,
        CursorInterface $cursor2
    ) {
        $familyReader->read()->willReturn(['code' => 'first_family'], ['code' => 'second_family'], null);
        $familyRepository->findOneByIdentifier('first_family')->willReturn($family1);

        $family1->getCode()->willReturn('first_family');
        $family2->getCode()->willReturn('second_family');

        $productAndProductModelQueryBuilderFactory->create()->willReturn($pqb1, $pqb2);

        $pqb1->addFilter('family', Operators::IN_LIST, ['first_family'])->shouldBeCalled();
        $pqb1->addFilter('parent', Operators::IS_EMPTY, null)->shouldBeCalled();
        $pqb1->execute()->willReturn($cursor1);

        $cursor1->rewind()->shouldBeCalled();
        $cursor1->valid()->willReturn(true, false);
        $cursor1->next()->willReturn($rootProductModel1);
        $cursor1->current()->willReturn($rootProductModel1);

        $productModelSaver->saveAll([$rootProductModel1])->shouldBeCalled();
        $productModelDescendantsSaver->save($rootProductModel1)->shouldBeCalled();

        $familyRepository->findOneByIdentifier('second_family')->willReturn($family2);

        $pqb2->addFilter('family', Operators::IN_LIST, ['second_family'])->shouldBeCalled();
        $pqb2->addFilter('parent', Operators::IS_EMPTY, null)->shouldBeCalled();
        $pqb2->execute()->willReturn($cursor2);

        $cursor2->rewind()->shouldBeCalled();
        $cursor2->valid()->willReturn(true, false);
        $cursor2->next()->willReturn($rootProductModel2);
        $cursor2->current()->willReturn($rootProductModel2);

        $productModelSaver->saveAll([$rootProductModel2])->shouldBeCalled();
        $productModelDescendantsSaver->save($rootProductModel2)->shouldBeCalled();

        $stepExecution->incrementSummaryInfo('process')->shouldBeCalledTimes(2);
        $this->setStepExecution($stepExecution);
        $this->execute();
    }

    function it_skips_if_the_family_is_unknown(
        $familyReader,
        $familyRepository,
        $productAndProductModelQueryBuilderFactory,
        $productModelSaver,
        $productModelDescendantsSaver,
        StepExecution $stepExecution
    ) {
        $familyReader->read()->willReturn(['code' => 'unkown_family'], null);
        $familyRepository->findOneByIdentifier('unkown_family')->willReturn(null);

        $stepExecution->incrementSummaryInfo('skip')->shouldBeCalled();

        $productAndProductModelQueryBuilderFactory->create()->shouldNotBeCalled();
        $productModelSaver->saveAll(Argument::any())->shouldNotBeCalled();
        $productModelDescendantsSaver->save(Argument::any())->shouldNotBeCalled();

        $this->setStepExecution($stepExecution);
        $this->execute();
    }

    function it_handles_invalid_lines(
        $familyReader,
        $familyRepository,
        $productAndProductModelQueryBuilderFactory,
        $productModelSaver,
        $productModelDescendantsSaver,
        StepExecution $stepExecution
    ) {
        $familyReader->read()->willThrow(InvalidItemException::class);
        $familyReader->read()->willReturn(null);

        $familyRepository->findOneByIdentifier(Argument::any())->shouldNotBeCalled();
        $productAndProductModelQueryBuilderFactory->create()->shouldNotBeCalled();
        $productModelSaver->saveAll(Argument::any())->shouldNotBeCalled();
        $productModelDescendantsSaver->save(Argument::any())->shouldNotBeCalled();

        $this->setStepExecution($stepExecution);
        $this->execute();
    }
}
