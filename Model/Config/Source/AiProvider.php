<?php
namespace Ceprika\Aitranslator\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AiProvider implements OptionSourceInterface
{
    public function toOptionArray()
    {
        return [
            ['value' => 'gpt', 'label' => __('GPT AI')],
            ['value' => 'claude', 'label' => __('Claude API 3.5 Sonnet')]
        ];
    }
}
