<?php
// Load composer
require __DIR__ . '/../vendor/autoload.php';

use Longman\TelegramBot\TelegramLog;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Exception\TelegramLogException;

$configs = include('../config.php');
if ($configs === false) {
    die('Unable to find config file!');
}

try {
    // Create Telegram API object
    $telegram = new Telegram($configs['api_key'], $configs['username']);

    $telegram->addCommandsPath($configs['commands_path']);

    if ($configs['log_errors'] === true) {
        TelegramLog::initErrorLog($configs['log_location'] . "/{$configs['username']}_error.log");
    }
    if ($configs['log_debug'] === true) {
        TelegramLog::initDebugLog($configs['log_location'] . "/{$configs['username']}_debug.log");
        TelegramLog::initUpdateLog($configs['log_location'] . "/{$configs['username']}_update.log");
    }

    $telegram->setDownloadPath($configs['download_path']);
    $telegram->setUploadPath($configs['upload_path']);

    if ($configs['enable_mysql'] === true) {
        $telegram->enableMySql($configs['mysql']);
    }

    //Note: the limiter can only be enabled if the database is enabled
    $telegram->enableLimiter();

    $telegram->handle();
} catch (TelegramException $e) {
    TelegramLog::error($e);
} catch (TelegramLogException $e) {
    //Logging went wrong, just swallow the exception in this case.
}
