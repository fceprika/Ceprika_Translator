<?php
namespace Ceprika\Aitranslator\Block\Adminhtml\Category\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;
use Magento\Backend\Block\Widget\Context;

class TranslateButton implements ButtonProviderInterface
{
    protected $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function getButtonData()
    {
        $categoryId = $this->context->getRequest()->getParam('id');
        return [
            'label' => __('Translate'),
            'on_click' => sprintf("location.href = '%s';", $this->getTranslateUrl($categoryId)),
            'class' => 'action-secondary',
            'sort_order' => 90
        ];
    }

    public function getTranslateUrl($categoryId)
    {
        return $this->context->getUrlBuilder()->getUrl('aitranslator/category/translate', ['category_id' => $categoryId]);
    }
}
