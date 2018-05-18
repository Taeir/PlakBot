<?php
// Load composer
require __DIR__ . '/vendor/autoload.php';

$configs = include('config.php');

try {
    // Create Telegram API object
    $telegram = new Longman\TelegramBot\Telegram($configs['api_key'], $configs['username']);

    // Set webhook
    $result = $telegram->setWebhook($configs['hook_url']);
    if ($result->isOk()) {
        echo $result->getDescription();
    }
} catch (Longman\TelegramBot\Exception\TelegramException $e) {
    // Log telegram errors
    echo $e->getMessage();
}