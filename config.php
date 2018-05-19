<?php
return array(
    //=============================================================================================
    //Telegram
    //=============================================================================================
    //Telegram Bot API Key
    'api_key'       => '',
    
    //Telegram Bot Username
    'username'      => '',
    
    //URL for the hook.php
    'hook_url'      => '',
    
    //=============================================================================================
    //MySQL
    //=============================================================================================
    'enable_mysql' => false,
    'mysql'        => [
        'host'     => 'localhost',
        'user'     => '',
        'password' => '',
        'database' => 'plakbot',
    ],
    
    //=============================================================================================
    //Folders
    //=============================================================================================
    //Path of the Commands folder
    'commands_path' => __DIR__ . '/Commands',
    
    //Path of the folder where the original stickers (webp) will be downloaded to
    'download_path' => __DIR__ . '/downloads',
    
    //Path of the folder where the converted stickers (png) will be stored for uploading
    'upload_path'   => __DIR__ . '/uploads',
    
    //If true then stickers uploaded to telegram are cached on the server to avoid converting more
    //than necessary.
    //Note that images are stored indefinitely, so you may want to clean them out from time to time.
    'cache_uploads' => true,
    
    //=============================================================================================
    //Conversion
    //=============================================================================================
    //If true, cloudconvert is used for converting from webp to png instead of the native PHP
    //functions.
    'cloudconvert'  => false,

    //The api key for cloud convert
    'cc_api_key'    => '',
    
    //=============================================================================================
    //Logging
    //=============================================================================================
    //Enable error logging
    'log_errors'    => true,
    
    //Enable debug logging
    'log_debug'     => false,
    
    //Location of the log files
    'log_location'  => __DIR__ . '/logs',
);
