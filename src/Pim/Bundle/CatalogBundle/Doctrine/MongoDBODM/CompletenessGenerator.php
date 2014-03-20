<?php

namespace Pim\Bundle\CatalogBundle\Doctrine\MongoDBODM;

use Pim\Bundle\CatalogBundle\Doctrine\CompletenessGeneratorInterface;
use Pim\Bundle\CatalogBundle\Entity\Channel;
use Pim\Bundle\CatalogBundle\Entity\Locale;
use Pim\Bundle\CatalogBundle\Entity\Family;
use Pim\Bundle\CatalogBundle\Model\ProductInterface;
use Pim\Bundle\CatalogBundle\Model\Completeness;
use Pim\Bundle\CatalogBundle\Factory\CompletenessFactory;
use Pim\Bundle\CatalogBundle\Validator\Constraints\ProductValueComplete;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Validator\ValidatorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Generate the completeness when Product are in MongoDBODM
 * storage. Please note that the generation for several products
 * is done on the MongoDB via a JS generated by the application via HTTP.
 *
 * This generator is only able to generate completeness for one product
 *
 * @author    Benoit Jacquemont <benoit@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class CompletenessGenerator implements CompletenessGeneratorInterface
{
    /**
     * @var DocumentManager;
     */
    protected $documentManager;

    /**
     * @var CompletenessFactory
     */
    protected $completenessFactory;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var string
     */
    protected $productClass;

    /**
     * Constructor
     *
     * @param DocumentManager     $documentManager
     * @param CompletenessFactory $completenessFactory
     * @param ValidatorInterface  $validator
     * @param String              $productClass
     */
    public function __construct(
        DocumentManager $documentManager,
        CompletenessFactory $completenessFactory,
        ValidatorInterface $validator,
        $productClass
    ) {
        $this->documentManager = $documentManager;
        $this->completenessFactory = $completenessFactory;
        $this->validator = $validator;
        $this->productClass = $productClass;
    }

    /**
     * {@inheritdoc}
     */
    public function generateMissingForProduct(ProductInterface $product)
    {
        if (null === $product->getFamily()) {
            return;
        }

        $completenesses = $this->buildProductCompletenesses($product);

        $product->setCompletenesses(new ArrayCollection($completenesses));

        $this->documentManager->flush($product);
    }

    /**
     * {@inheritdoc}
     */
    public function generateMissingForChannel(Channel $channel)
    {
    }

    /**
     * Build the completeness for the product
     *
     * @param ProductInterface $product
     *
     * @return array
     */
    public function buildProductCompletenesses(ProductInterface $product)
    {
        $completenesses = array();

        $stats = $this->collectStats($product);

        foreach ($stats as $channelStats) {
            $channel = $channelStats['object'];
            $channelData = $channelStats['data'];
            $channelRequiredCount = $channelStats['required_count'];

            foreach ($channelData as $localeStats) {
                $locale = $localeStats['object'];
                $localeData = $localeStats['data'];

                $completeness = $this->completenessFactory->build(
                    $channel,
                    $locale,
                    $localeData['missing_count'],
                    $channelRequiredCount
                );

                $completenesses[] = $completeness;
            }
        }

        return $completenesses;
    }

    /**
     * Generate statistics on the product completeness
     *
     * @param ProductInterface $product
     *
     * @return array $stats
     */
    protected function collectStats(ProductInterface $product)
    {
        $stats = array();

        if (null === $family = $product->getFamily()) {
            return $stats;
        }

        foreach ($family->getAttributeRequirements() as $req) {
            if (!$req->isRequired()) {
                continue;
            }
            $channel = $req->getChannel()->getCode();
            $locales = $req->getChannel()->getLocales();

            if (!isset($stats[$channel])) {
                $stats[$channel]['object'] = $req->getChannel();
                $stats[$channel]['data'] = array();
                $stats[$channel]['required_count'] = 0;
            }

            $completeConstraint = new ProductValueComplete(array('channel' => $req->getChannel()));

            $stats[$channel]['required_count']++;

            foreach ($locales as $localeObject) {
                $locale = $localeObject->getCode();
                if (!isset($stats[$channel]['data'][$locale])) {
                    $stats[$channel]['data'][$locale] = array();
                    $stats[$channel]['data'][$locale]['object'] = $localeObject;
                    $stats[$channel]['data'][$locale]['data'] = array();
                    $stats[$channel]['data'][$locale]['data']['missing_count'] = 0;
                }

                $attribute = $req->getAttribute();
                $value = $product->getValue(
                    $attribute->getCode(),
                    $attribute->isLocalizable() ? $locale : null,
                    $attribute->isScopable() ? $channel : null
                );

                if (!$value || $this->validator->validateValue($value, $completeConstraint)->count() > 0) {
                    $stats[$channel]['data'][$locale]['data']['missing_count'] ++;
                }
            }
        }

        return $stats;
    }

    /**
     * {@inheritdoc}
     */
    public function generateMissing()
    {
        $this->generate();
    }

    /**
     * Generate missing completenesses for a channel if provided or a product
     * if provided.
     *
     * @param Product $product
     * @param Channel $channel
     *
     */
    protected function generate(ProductInterface $product = null, Channel $channel = null)
    {
        $products = $this->getMissingQuery($product, $channel);

        foreach ($products as $product) {
            $this->generateMissingForProduct($product);
        }
    }

    /**
     * Get the query part to search for product where the completenesses
     * are missing. Apply only to the channel or product if provided.
     *
     * @param Product $product
     * @param Channel $channel
     *
     * @return Expr $findQuery
     */
    protected function getFindMissingQuery(ProductInterface $product = null, Channel $channel = null)
    {
        $findQuery = new Expr();
        if (null !== $product) {
            $findQuery->field('_id')->equals($product->getId());
        } else {
            $combinations = $this->getCombinations($channel);
            $find->addOr();
            
            foreach ($combinations as $combination) {
                $findQuery->field('normalizedData.completenesses.'.$combination)->exists(false);
            }
        }

        return $findQuery;
    }

    /**
     * Generate a list of potential completeness value from existing channel
     * or from the provided channel
     *
     * @param Channel $channel
     *
     * @return array
     */
    protected function getCombinations(Channel $channel = null)
    {
        $channels = array();
        $combinations = array();

        foreach ($channels as $channel)
        {
            if (null !== $channel) {
                $channels = [$channel];
            } else {
                $channels = $this->channelManager->getFullChannels();
            }

            $locales = $channel->getLocales();
            foreach ($locales as $locale) {
                $combinations[] = $channel->getCode().'-'.$locale->getCode();
            }
        }

        return $combinations;
    }


    /**
     * {@inheritdoc}
     */
    public function schedule(ProductInterface $product)
    {
        $product->setCompletenesses(new ArrayCollection());

        $this->documentManager->flush($product);
    }

    /**
     * {@inheritdoc}
     */
    public function scheduleForFamily(Family $family)
    {
        $productQb = $this->documentManager->createQueryBuilder($this->productClass);

        $productQb
            ->hydrate(false)
            ->findAndUpdate()
            ->field('family')->equals($family->getId())
            ->field('completenesses')->unsetField()
            ->field('normalizedData.completenesses')->unsetField()
            ->getQuery()
            ->execute();
    }
}
