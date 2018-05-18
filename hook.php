<?php
// Load composer
require __DIR__ . '/vendor/autoload.php';

include('config.php');

$commands_paths = [
    __DIR__ . '/Commands/',
];

try {
    // Create Telegram API object
    $telegram = new Longman\TelegramBot\Telegram($bot_api_key, $bot_username);
    
    $telegram->addCommandsPaths($commands_paths);
    
    Longman\TelegramBot\TelegramLog::initErrorLog(__DIR__ . "/logs/{$bot_username}_error.log");
    Longman\TelegramBot\TelegramLog::initDebugLog(__DIR__ . "/logs/{$bot_username}_debug.log");
    Longman\TelegramBot\TelegramLog::initUpdateLog(__DIR__ . "/logs/{$bot_username}_update.log");
    
    $telegram->setDownloadPath(__DIR__ . '/downloads');
    $telegram->setUploadPath(__DIR__ . '/uploads');
    
    $telegram->enableLimiter();
    
    $telegram->handle();
} catch (Longman\TelegramBot\Exception\TelegramException $e) {
    file_put_contents(__DIR__ . '/logs/error.log', 'Hook error: ' . $e . PHP_EOL, FILE_APPEND | LOCK_EX);
    Longman\TelegramBot\TelegramLog::error($e);
} catch (Longman\TelegramBot\Exception\TelegramLogException $e) {
    file_put_contents(__DIR__ . '/logs/error.log', 'Hook error: Log exception: ' . $e . PHP_EOL, FILE_APPEND | LOCK_EX);
}
