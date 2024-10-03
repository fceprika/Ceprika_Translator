<?php
namespace Ceprika\Aitranslator\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Ceprika\Aitranslator\Model\Translator;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\ProgressBar;

class TranslateProducts extends Command
{
    const XML_PATH_AI_PROVIDER = 'ai_translator/settings/ai_provider';
    const XML_PATH_STORE_NAME = 'general/store_information/name';
    const ARG_STORE_CODE = 'store_code';
    const BATCH_SIZE = 100;

    public function __construct(
        private State $state,
        private StoreManagerInterface $storeManager,
        private Translator $translator,
        private ScopeConfigInterface $scopeConfig,
        private CollectionFactory $productCollectionFactory,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('products:translate')
            ->setDescription('Translate all product attributes into a specified language for a given store view')
            ->addArgument(self::ARG_STORE_CODE, InputArgument::REQUIRED, 'Store View Code');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->setAreaCode('frontend');
            $storeCode = $input->getArgument(self::ARG_STORE_CODE);
            $store = $this->getStore($storeCode);
            $locale = $this->getLocale($store);
            $aiProvider = $this->getAiProvider($store);
            $storeName = $this->getStoreName($store);

            $output->writeln(sprintf('Using AI Provider: %s', $aiProvider));
            $output->writeln(sprintf('Store Name: %s', $storeName));

            $totalProducts = $this->getTotalProductCount();
            $progressBar = new ProgressBar($output, $totalProducts);
            $progressBar->start();

            $page = 1;
            do {
                $products = $this->getProductBatch($page);
                foreach ($products as $product) {
                    $this->processProduct($product, $store, $locale, $storeName, $output);
                    $progressBar->advance();
                }
                $page++;
            } while ($page <= $products->getLastPageNumber());

            $progressBar->finish();
            $output->writeln("\nTranslation completed successfully.");
            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $this->logger->critical('Error in TranslateProducts command: ' . $e->getMessage(), ['exception' => $e]);
            $output->writeln('<error>An unexpected error occurred. Please check the logs for more information.</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    private function getStore($storeCode)
    {
        try {
            return $this->storeManager->getStore($storeCode);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new \InvalidArgumentException(sprintf('Store view with code "%s" does not exist.', $storeCode));
        }
    }

    private function getLocale($store)
    {
        return $this->scopeConfig->getValue('general/locale/code', \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getId());
    }

    private function getAiProvider($store)
    {
        return $this->scopeConfig->getValue(self::XML_PATH_AI_PROVIDER, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getId());
    }

    private function getStoreName($store)
    {
        return $this->scopeConfig->getValue(self::XML_PATH_STORE_NAME, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $store->getId());
    }

    private function getTotalProductCount()
    {
        return $this->productCollectionFactory->create()->getSize();
    }

    private function getProductBatch($page)
    {
        return $this->productCollectionFactory->create()
            ->addAttributeToSelect(['name', 'description', 'short_description', 'meta_title', 'meta_description', 'meta_keyword'])
            ->addAttributeToFilter('entity_id', 2)
            ->setPageSize(self::BATCH_SIZE)
            ->setCurPage($page);
    }

    private function processProduct($product, $store, $locale, $storeName, $output)
    {
        try {
            $product->setStoreId($store->getId());
            $this->translateAttribute($product, 'name', $locale);
            $this->translateDescription($product, 'description', $locale);
            $this->translateAttribute($product, 'short_description', $locale);
            $this->translateMetaTitle($product, $locale, $storeName);
            $this->translateAttribute($product, 'meta_description', $locale);
            $this->translateAttribute($product, 'meta_keyword', $locale);
            $product->save();
            $this->logger->info(sprintf('Product ID %d translated successfully.', $product->getId()));
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error translating product ID %d: %s', $product->getId(), $e->getMessage()));
            $output->writeln(sprintf('<error>Error translating product ID %d: %s</error>', $product->getId(), $e->getMessage()));
        }
    }

    private function translateAttribute($product, $attributeCode, $locale)
    {
        $value = $product->getData($attributeCode);
        if ($this->isNotEmptyOrNull($value)) {
            $translatedValue = $this->translator->translate($value, $locale);
            $product->setData($attributeCode, $translatedValue);
        }
    }

    private function translateDescription($product, $attributeCode, $locale)
    {
        $value = $product->getData($attributeCode);
        if ($this->isNotEmptyOrNull($value)) {
            $translatedValue = $this->translator->translateDescription($value, $locale);
            $product->setData($attributeCode, $translatedValue);
        }
    }

    private function translateMetaTitle($product, $locale, $storeName)
    {
        $metaTitle = $product->getMetaTitle();
        if ($this->isNotEmptyOrNull($metaTitle)) {
            $translatedMetaTitle = $this->translator->translate($metaTitle, $locale);
        } else {
            $translatedMetaTitle = $product->getName(); // Use the translated product name if meta title is empty
        }
        $newMetaTitle = $storeName . ' - ' . $translatedMetaTitle;
        $product->setMetaTitle($newMetaTitle);
    }

    private function isNotEmptyOrNull($value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
