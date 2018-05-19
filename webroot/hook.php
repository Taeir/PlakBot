<?php

// Load composer
require __DIR__ . '/../vendor/autoload.php';

use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\TelegramLog;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Exception\TelegramLogException;
$configs = include('../config.php');

$commands_paths = [
    __DIR__ . '/../Commands/',
];

try {
    // Create Telegram API object

    $userName = $configs['username'];

    $telegram = new Telegram($configs['api_key'], $userName);
    $telegram->addCommandsPaths($commands_paths);

    if ($configs['log_errors'] === true) {
        TelegramLog::initErrorLog($configs['log_location'] . "/{$userName}_error.log");
    }
    if ($configs['log_debug'] === true) {
        TelegramLog::initDebugLog($configs['log_location'] . "/{$userName}_debug.log");
        TelegramLog::initUpdateLog($configs['log_location'] . "/{$userName}_update.log");
    }
    $telegram->setDownloadPath($configs['download_path']);
    $telegram->setUploadPath($configs['upload_path']);

    $telegram->enableLimiter();

    $telegram->handle();
} catch (TelegramException $e) {
    Longman\TelegramBot\TelegramLog::error($e);
} catch (TelegramLogException $e) {
    //Logging went wrong, just swallow the exception in this case.
}
