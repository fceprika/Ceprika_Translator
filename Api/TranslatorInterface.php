<?php
namespace Ceprika\Aitranslator\Api;

interface TranslatorInterface
{
    /**
     * Translate text to the specified language
     *
     * @param string $text
     * @param string $targetLanguage
     * @return string
     */
    public function translate($text, $targetLanguage);
}
