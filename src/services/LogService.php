<?php

namespace roadsterworks\craftshopifysync\services;

use Craft;
use craft\helpers\Json;
use yii\base\Component;
use yii\log\Logger;

/**
 * Writes to `storage/logs/craft-shopify-sync-YYYY-MM-DD.log`, with context as single-line JSON.
 */
class LogService extends Component
{
    public const CATEGORY = 'craft-shopify-sync';

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(Logger::LEVEL_INFO, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(Logger::LEVEL_WARNING, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(Logger::LEVEL_ERROR, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(int $level, string $message, array $context): void
    {
        Craft::getLogger()->log($context === [] ? $message : $message . ' ' . Json::encode($context), $level, self::CATEGORY);
    }
}
