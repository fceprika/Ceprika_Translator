<?php
namespace Ceprika\Aitranslator\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Catalog\Model\CategoryFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Ceprika\Aitranslator\Model\Translator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TranslateCategories extends Command
{
    const XML_PATH_STORE_NAME = 'general/store_information/name';
    const XML_PATH_AI_PROVIDER = 'ai_translator/settings/ai_provider';

    public function __construct(
        private State $state,
        private CategoryFactory $categoryFactory,
        private StoreManagerInterface $storeManager,
        private Translator $translator,
        private ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('category:translate')
            ->setDescription('Translate all categories into a specified language for a given store view')
            ->addArgument('store_code', InputArgument::REQUIRED, 'Store View Code');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->state->setAreaCode('frontend');
            $storeCode = $input->getArgument('store_code');

            try {
                $store = $this->storeManager->getStore($storeCode);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $output->writeln(sprintf('<error>Store view with code "%s" does not exist.</error>', $storeCode));
                return Cli::RETURN_FAILURE;
            }

            $locale = $this->scopeConfig->getValue(
                'general/locale/code',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            $aiProvider = $this->scopeConfig->getValue(
                self::XML_PATH_AI_PROVIDER,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $store->getId()
            );

            $output->writeln(sprintf('Using AI Provider: %s', $aiProvider));

            $categories = $this->categoryFactory->create()->getCollection()
             //   ->addFieldToFilter('entity_id', 11)
                ->addAttributeToSelect('*');

            foreach ($categories as $category) {
                $this->processCategory($category, $store, $locale, $output);
            }

            return Cli::RETURN_SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>An error occurred: %s</error>', $e->getMessage()));
            return Cli::RETURN_FAILURE;
        }
    }

    private function processCategory($category, $store, $locale, $output)
    {
        $category->setStoreId($store->getId());

        try {
            $this->translateName($category, 'name', $locale, $output);
            $this->translateAttribute($category, 'description', $locale, $output);
            $this->translateAttribute($category, 'meta_title', $locale, $output);
            $this->translateAttribute($category, 'meta_keywords', $locale, $output);
            $this->translateAttribute($category, 'meta_description', $locale, $output);

            $category->save();
            $output->writeln(sprintf("Category '%s' processed successfully.", $category->getName()));
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error processing category %s: %s</error>', $category->getId(), $e->getMessage()));
        }
    }

    private function translateAttribute($category, $attributeCode, $locale, $output)
    {
        $value = $category->getData($attributeCode);
        if (!empty($value)) {
            $translatedValue = $this->translator->translate($value, $locale);
            $category->setData($attributeCode, $translatedValue);
            $output->writeln(sprintf("%s translated: %s", ucfirst($attributeCode), substr($translatedValue, 0, 50) . '...'));
        } else {
            $output->writeln(sprintf("%s is empty, skipping translation.", ucfirst($attributeCode)));
        }
    }

    private function translateName($product, $attributeCode, $locale)
    {
        $value = $product->getData($attributeCode);
        if (!empty($value)) {
            $translatedValue = $this->translator->translateName($value, $locale);
            $product->setData($attributeCode, $translatedValue);
        }
    }
}