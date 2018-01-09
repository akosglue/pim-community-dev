<?php

declare(strict_types=1);

namespace spec\Pim\Component\Connector\Job;

use Akeneo\Component\Batch\Item\InvalidItemException;
use Akeneo\Component\Batch\Item\ItemReaderInterface;
use Akeneo\Component\Batch\Model\StepExecution;
use Akeneo\Component\StorageUtils\Cache\CacheClearerInterface;
use Akeneo\Component\StorageUtils\Saver\BulkSaverInterface;
use Akeneo\Component\StorageUtils\Saver\SaverInterface;
use PhpSpec\ObjectBehavior;
use Pim\Component\Catalog\Model\FamilyInterface;
use Pim\Component\Catalog\Model\ProductModelInterface;
use Pim\Component\Catalog\Repository\FamilyRepositoryInterface;
use Pim\Component\Catalog\Repository\ProductModelRepositoryInterface;
use Pim\Component\Connector\Job\ComputeDataRelatedToFamilyVariantsTasklet;
use Prophecy\Argument;

class ComputeDataRelatedToFamilyVariantsTaskletSpec extends ObjectBehavior
{
    function let(
        FamilyRepositoryInterface $familyRepository,
        ProductModelRepositoryInterface $productModelRepository,
        ItemReaderInterface $familyReader,
        BulkSaverInterface $productModelSaver,
        SaverInterface $productModelDescendantsSaver,
        CacheClearerInterface $cacheClearer
    ) {
        $this->beConstructedWith(
            $familyRepository,
            $productModelRepository,
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
        $productModelRepository,
        $productModelSaver,
        $productModelDescendantsSaver,
        FamilyInterface $family,
        ProductModelInterface $rootProductModel,
        StepExecution $stepExecution
    ) {
        $familyReader->read()->willReturn(['code' => 'my_family'], null);
        $familyRepository->findOneByIdentifier('my_family')->willReturn($family);
        $productModelRepository->findRootProductModelsWithFamily($family)->willReturn([$rootProductModel]);
        $productModelSaver->saveAll([$rootProductModel])->shouldBeCalled();
        $productModelDescendantsSaver->save($rootProductModel)->shouldBeCalled();

        $stepExecution->incrementSummaryInfo('process')->shouldBeCalled();

        $this->setStepExecution($stepExecution);
        $this->execute();
    }

    function it_saves_the_product_models_and_its_descendants_belonging_to_the_families(
        $familyReader,
        $familyRepository,
        $productModelRepository,
        $productModelSaver,
        $productModelDescendantsSaver,
        FamilyInterface $family1,
        FamilyInterface $family2,
        ProductModelInterface $rootProductModel1,
        ProductModelInterface $rootProductModel2,
        StepExecution $stepExecution
    ) {
        $familyReader->read()->willReturn(['code' => 'first_family'], ['code' => 'second_family'], null);
        $familyRepository->findOneByIdentifier('first_family')->willReturn($family1);
        $productModelRepository->findRootProductModelsWithFamily($family1)->willReturn([$rootProductModel1]);
        $productModelSaver->saveAll([$rootProductModel1])->shouldBeCalled();
        $productModelDescendantsSaver->save($rootProductModel1)->shouldBeCalled();

        $familyRepository->findOneByIdentifier('second_family')->willReturn($family2);
        $productModelRepository->findRootProductModelsWithFamily($family2)->willReturn([$rootProductModel2]);
        $productModelSaver->saveAll([$rootProductModel2])->shouldBeCalled();
        $productModelDescendantsSaver->save($rootProductModel2)->shouldBeCalled();

        $stepExecution->incrementSummaryInfo('process')->shouldBeCalledTimes(2);
        $this->setStepExecution($stepExecution);
        $this->execute();
    }

    function it_skips_if_the_family_is_unknown(
        $familyReader,
        $familyRepository,
        $productModelRepository,
        $productModelSaver,
        $productModelDescendantsSaver,
        StepExecution $stepExecution
    ) {
        $familyReader->read()->willReturn(['code' => 'unkown_family'], null);
        $familyRepository->findOneByIdentifier('unkown_family')->willReturn(null);

        $stepExecution->incrementSummaryInfo('skip')->shouldBeCalled();

        $productModelRepository->findRootProductModelsWithFamily(Argument::any())->shouldNotBeCalled();
        $productModelSaver->saveAll(Argument::any())->shouldNotBeCalled();
        $productModelDescendantsSaver->save(Argument::any())->shouldNotBeCalled();

        $this->setStepExecution($stepExecution);
        $this->execute();
    }

    function it_handles_invalid_lines(
        $familyReader,
        $familyRepository,
        $productModelRepository,
        $productModelSaver,
        $productModelDescendantsSaver,
        StepExecution $stepExecution
    ) {
        $familyReader->read()->willThrow(InvalidItemException::class);
        $familyReader->read()->willReturn(null);

        $familyRepository->findOneByIdentifier(Argument::any())->shouldNotBeCalled();
        $productModelRepository->findRootProductModelsWithFamily(Argument::any())->shouldNotBeCalled();
        $productModelSaver->saveAll(Argument::any())->shouldNotBeCalled();
        $productModelDescendantsSaver->save(Argument::any())->shouldNotBeCalled();

        $this->setStepExecution($stepExecution);
        $this->execute();
    }
}
