<?php

// Load composer
require __DIR__ . '/../vendor/autoload.php';

$configs = include('../config.php');

$commands_paths = [
    __DIR__ . '/../Commands/',
];

try {
    // Create Telegram API object

    $userName = $configs['username'];

    $telegram = new Longman\TelegramBot\Telegram($configs['api_key'], $userName);
    $telegram->addCommandsPaths($commands_paths);

    if ($configs['log_errors'] === true) {
        Longman\TelegramBot\TelegramLog::initErrorLog($configs['log_location'] . "/{$userName}_error.log");
    }
    if ($configs['log_debug'] === true) {
        Longman\TelegramBot\TelegramLog::initDebugLog($configs['log_location'] . "/{$userName}_debug.log");
        Longman\TelegramBot\TelegramLog::initUpdateLog($configs['log_location'] . "/{$userName}_update.log");
    }
    $telegram->setDownloadPath($configs['download_path']);
    $telegram->setUploadPath($configs['upload_path']);

    $telegram->enableLimiter();

    $telegram->handle();
} catch (Longman\TelegramBot\Exception\TelegramException $e) {
    Longman\TelegramBot\TelegramLog::error($e);
} catch (Longman\TelegramBot\Exception\TelegramLogException $e) {
    //Logging went wrong, just swallow the exception in this case.
}
