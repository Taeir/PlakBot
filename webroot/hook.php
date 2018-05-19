<?php
error_reporting(-1);
ini_set('display_errors', 'On');

// Load composer
require __DIR__ . '/../vendor/autoload.php';

$configs = include('../config.php');

$commands_paths = [
    __DIR__ . '/../Commands/',
];

    // Create Telegram API object

    $userName = $configs['username'];

    $telegram = new Longman\TelegramBot\Telegram($configs['api_key'], $userName);
    $telegram->addCommandsPaths($commands_paths);

//var_dump($telegram->getCommandsList()); echo "ok";

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

