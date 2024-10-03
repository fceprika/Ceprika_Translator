<?php
namespace Ceprika\Aitranslator\Model;

use Ceprika\Aitranslator\Api\TranslatorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Translator implements TranslatorInterface
{
    protected $curl;
    protected $scopeConfig;
    protected $logger;
    protected $encryptor;
    const XML_PATH_AI_PROVIDER = 'ai_translator/settings/ai_provider';
    const XML_PATH_GPT_API_KEY = 'ai_translator/settings/gpt_api_key';
    const XML_PATH_CLAUDE_API_KEY = 'ai_translator/settings/claude_api_key';

    public function __construct(
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        EncryptorInterface $encryptor
    ) {
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->encryptor = $encryptor;
    }

    public function translate($text, $targetLanguage)
    {
        return $this->callAiApi($text, $targetLanguage, 'translate');
    }

    public function translateDescription($text, $targetLanguage)
    {
        return $this->callAiApi($text, $targetLanguage, 'description');
    }

    public function translateName($text, $targetLanguage)
    {
        return $this->callAiApi($text, $targetLanguage, 'name');
    }

    public function generateDescription($text, $targetLanguage)
    {
        return $this->callAiApi($text, $targetLanguage, 'description');
    }

    public function generateMetaTags($text, $targetLanguage, $metaType)
    {
        return $this->callAiApi($text, $targetLanguage, 'meta', $metaType);
    }

    private function callAiApi($text, $targetLanguage, $type, $metaType = null)
    {
        $aiProvider = $this->scopeConfig->getValue(self::XML_PATH_AI_PROVIDER, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        if ($aiProvider === 'gpt') {
            return $this->callOpenAiApi($text, $targetLanguage, $type, $metaType);
        } elseif ($aiProvider === 'claude') {
            return $this->callClaudeApi($text, $targetLanguage, $type, $metaType);
        } else {
            throw new \Exception('Invalid AI provider selected.');
        }
    }

    private function callOpenAiApi($text, $targetLanguage, $type, $metaType = null)
    {
        $apiKey = $this->getDecryptedApiKey(self::XML_PATH_GPT_API_KEY);

        if (!$apiKey) {
            throw new \Exception('GPT API key is not set in the configuration.');
        }

        $url = "https://api.openai.com/v1/chat/completions";
        $headers = [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json"
        ];

        $content = $this->prepareContent($text, $targetLanguage, $type, $metaType);

        $data = json_encode([
            'model' => 'gpt-4o',
            'messages' => $content,
            'max_tokens' => 4096
        ]);

        return $this->makeApiCall($url, $headers, $data);
    }

    private function callClaudeApi($text, $targetLanguage, $type, $metaType = null)
    {
        $apiKey = $this->getDecryptedApiKey(self::XML_PATH_CLAUDE_API_KEY);

        if (!$apiKey) {
            throw new \Exception('Claude API key is not set in the configuration.');
        }

        $url = "https://api.anthropic.com/v1/messages";
        $headers = [
            "x-api-key: " . $apiKey,
            "Content-Type: application/json",
            "anthropic-version: 2023-06-01"
        ];

        $content = $this->prepareContent($text, $targetLanguage, $type, $metaType);

        // Concatenate system and user messages
        $combinedContent = $content[0]['content'] . "\n\n" . $content[1]['content'];

        $data = json_encode([
            'model' => 'claude-3-sonnet-20240229',
            'max_tokens' => 4096,
            'messages' => [
                ['role' => 'user', 'content' => $combinedContent]
            ]
        ]);

        return $this->makeApiCall($url, $headers, $data);
    }

    private function prepareContent($text, $targetLanguage, $type, $metaType = null)
    {
        switch ($type) {
            case 'name':
                return [
                    ['role' => 'system', 'content' => 'You are an professional interpret. Just answer with the translated text. Answer with plain text. Asnwser only with the translated name without quotes.'],
                    ['role' => 'user', 'content' => "Create a name it should always start with an uppercase letter in $targetLanguage for : \"$text\""]
                ];
            case 'description':
                return [
                    ['role' => 'system', 'content' => 'You are an expert SEO. Generate a perfect e-commerce category description with optimized HTML code for SEO. Just use span, strong and p tags. Never use quotes. Answer with plain text.'],
                    ['role' => 'user', 'content' => "Generate a short description in $targetLanguage for : \"$text\""]
                ];
            case 'meta':
                return [
                    ['role' => 'system', 'content' => "You are an expert SEO. Generate a perfect e-commerce $metaType. Answer with plain text."],
                    ['role' => 'user', 'content' => "Generate a $metaType in $targetLanguage for : \"$text\""]
                ];
            case 'translate':
            default:
                return [
                    ['role' => 'system', 'content' => 'You are a translator. Respond with only the translated text, no quotes, no punctuation, just the text itself. I will give you country codes (fr_FR = french, France, fr_BE = french, Belgium, en_GB = english, Great Britain). You need to translate in the correct region language.'],
                    ['role' => 'user', 'content' => "Translate this text to $targetLanguage: \"$text\""]
                ];
        }
    }

    private function makeApiCall($url, $headers, $data)
    {
        $this->logger->info('AI API Request:', ['url' => $url, 'headers' => $headers, 'data' => $data]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->logger->info('AI API Response:', ['response' => $response, 'httpCode' => $httpCode]);

        $result = json_decode($response, true);

        if ($httpCode !== 200) {
            $this->logger->error('AI API Error:', ['response' => $response, 'httpCode' => $httpCode]);
            throw new \Exception('AI API error: ' . ($result['error']['message'] ?? $response));
        }

        if (isset($result['content'][0]['text'])) {
            return trim($result['content'][0]['text']);
        } else {
            $this->logger->error('AI API Response Error:', ['response' => $response]);
            throw new \Exception('AI API response is invalid: ' . $response);
        }
    }

    private function getDecryptedApiKey($path)
    {
        $encryptedApiKey = $this->scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        return $this->encryptor->decrypt($encryptedApiKey);
    }
}