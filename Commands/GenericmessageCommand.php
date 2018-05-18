<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use \CloudConvert\Api;
use Longman\TelegramBot\Entities\File;
use Longman\TelegramBot\Entities\Sticker;
use Longman\TelegramBot\Entities\StickerSet;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Longman\TelegramBot\Commands\SystemCommand;
/**
 * Generic message command
 */
class GenericmessageCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'genericmessage';
    /**
     * @var string
     */
    protected $description = 'Handle generic message';
    /**
     * @var string
     */
    protected $version = '1.1.0';
    
    /**
     * @var bool
     */
    protected $need_mysql = false;
    
    /**
     * @var array
     */
    protected $configs = [];
    
    /**
     * Constructor
     *
     * @param \Longman\TelegramBot\Telegram        $telegram
     * @param \Longman\TelegramBot\Entities\Update $update
     */
    public function __construct(Telegram $telegram, Update $update = null)
    {
        parent::__construct($telegram, $update);
        
        $this->configs = include(__DIR__ . '/../config.php');
    }
    
    /**
     * Execute command
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute()
    {
        $message      = $this->getMessage();
        $message_id   = $message->getMessageId();
        $chat_id      = $message->getChat()->getId();
        $from         = $message->getFrom();
        $user_id      = $from->getId();
        
        //Log the message
        $this->logMsg('Received message ' . $message);

        //If not sticker, fail quickly.
        if ($message->getType() !== 'sticker') {
            $this->logMsg('User ' . $chat_id . ' sent a non-sticker.');
            return $this->sendReply($chat_id, $message_id, 'Please send a sticker.');
        }
        
        $original_sticker  = $message->getSticker();
        $emojis            = $original_sticker->getEmoji();
        $original_file_id  = $original_sticker->getFileId();
        $original_set_name = $original_sticker->getSetName();
        $this->logMsg('Received sticker [id: ' . $original_file_id . '; emojis: ' . $emojis . '; set: ' . $original_set_name . '] from user ' . $user_id . ' (' . $from->getFirstName() . ')');
        
        //Check if already in the pack.
        $sticker_set_name  = 'user_' . $user_id . '_by_' . $this->telegram->getBotUsername();
        if ($sticker_set_name === $original_set_name) {
            $delete_response = Request::deleteStickerFromSet(['sticker' => $original_file_id]);
            if ($delete_response->isOk()) {
                $this->logMsg('Deleted ' . $original_file_id . ' from set');
                return $this->sendReply($chat_id, $message_id, 'Deleted sticker from pack.');
            } else {
                $this->errMsg('deleteStickerFromSet(' . $original_file_id . ') failed: ' . $delete_responsedelete_response->getDescription());
                return $this->sendReply($chat_id, $message_id, 'Deletion of sticker failed: ' . $delete_response->getDescription());
            }
        }
        
        //Send that we are uploading a photo
        Request::sendChatAction(['chat_id' => $chat_id, 'action' => 'upload_photo']);
        
        //Check if already converted, skip download and conversion if so.
        $png_file_path  = $this->telegram->getUploadPath() . '/' . $original_file_id . '.png';
        if (!file_exists($png_file_path)) {
            //Get original file
            $this->logMsg('Downloading fid: ' . $original_file_id . ' for user ' . $user_id);
            $get_original_sticker_response = Request::getFile(['file_id' => $original_file_id]);
            if (!$get_original_sticker_response->isOk()) {
                $this->errMsg('getFile(' . $original_file_id . ') failed: ' . $get_original_sticker_response->getDescription());
                return $this->sendReply($chat_id, $message_id, 'Unable to download this sticker: ' . $get_original_sticker_response->getDescription());
            }
            //Download original file
            $webp_file = $get_original_sticker_response->getResult();
            $download_success = Request::downloadFile($webp_file);
            if ($download_success !== true) {
                $this->errMsg('downloadFile(' . $original_file_id . ') failed: ' . $download_success);
                return $this->sendReply($chat_id, $message_id, 'Unable to download this sticker: ' . $download_success);
            }

            //Convert from webp to png
            $webp_file_name = $webp_file->getFilePath();
            $webp_file_path = $this->telegram->getDownloadPath() . '/' . $webp_file_name;
            $this->logMsg('Converting ' . $original_file_id. ' (' . $webp_file_name . ')');
            
            if ($this->configs['cloudconvert'] !== true) {
                //Conversion with native PHP
                $im = null;
                try {
                    $im = imagecreatefromwebp($webp_file_path);
                    imagepng($im, $png_file_path);
                } finally {
                    //Cleanup
                    if ($im !== null) {
                        imagedestroy($im);
                    }
                    unlink($webp_file_path);
                }
            } else {
                //Conversion with CloudConvert.
                $webp_file_handle = null;
                try {
                    $api = new \CloudConvert\Api($this->configs['cc_api_key']);
                    $webp_file_handle = fopen($webp_file_path, 'r');
                    $api->convert([
                            'inputformat' => 'webp',
                            'outputformat' => 'png',
                            'input' => 'upload',
                            'file' => $webp_file_handle,
                        ])
                        ->wait()
                        ->download($png_file_path);
                    $this->logMsg('Converted ' . $webp_file_name . ' to ' . $original_file_id . '.png');
                } catch (\CloudConvert\Exceptions\ApiBadRequestException $e) {
                    $this->errMsg('Conversion failed: ApiBadRequestException: ' . $e->getMessage());
                    return $this->sendReply($chat_id, $message_id, 'Conversion failed: ' . $e->getMessage());
                } catch (\CloudConvert\Exceptions\ApiConversionFailedException $e) {
                    $this->errMsg('Conversion failed: ApiConversionFailedException (broken input file?): ' . $e->getMessage());
                    return $this->sendReply($chat_id, $message_id, 'This sticker cannot be converted: ' . $e->getMessage());
                } catch (\CloudConvert\Exceptions\ApiTemporaryUnavailableException $e) {
                    $this->errMsg('Conversion failed: API temporarily unavailable: ' . $e->getMessage() . PHP_EOL . ' Retry after ' . $e->retryAfter);
                    return $this->sendReply($chat_id, $message_id, 'Conversion failed: API temporarily unavailable: ' . $e->getMessage() . '. Retry in ' . $e->retryAfter . ' seconds');
                } catch (\Throwable $e) {
                    $this->errMsg('Conversion failed with generic throwable: ' . $e->getMessage());
                    return $this->sendReply($chat_id, $message_id, 'Conversion failed for an unknown reason: ' . $e->getMessage());
                } finally {
                    //Cleanup and delete file
                    if ($webp_file_handle !== null) {
                        fclose($webp_file_handle);
                    }
                    
                    unlink($webp_file_path);
                }
            }
        } else {
            $this->logMsg('Skipped download and conversion, using ' . $original_file_id . '.png from cache');
        }
        
        try {
            //Add to the existing sticker set, or create a new sticker set.
            $add_sticker_or_create_sticker_set_data = [
                'user_id'     => $user_id,
                'name'        => $sticker_set_name,
                'png_sticker' => Request::encodeFile($png_file_path),
                'emojis'      => $emojis,
            ];
            $this->logMsg('Checking if sticker set ' . $sticker_set_name . ' already exists');
            if ($this->stickerSetExists($sticker_set_name)) {
                //Add sticker to existing set
                $this->logMsg('Sticker set ' . $sticker_set_name . ' exists, adding sticker to set');
                $add_sticker_to_set_response = Request::addStickerToSet($add_sticker_or_create_sticker_set_data);
                if (!$add_sticker_to_set_response->isOk()) {
                    $this->errMsg('Unable to add sticker to set ' . $sticker_set_name . ': ' . $add_sticker_to_set_response->getDescription());
                    return $this->sendReply($chat_id, $message_id, 'Unable to add sticker to sticker set ' . $sticker_set_name . ': ' . $add_sticker_to_set_response->getDescription());
                }
            } else {
                //Create new sticker set 
                $this->logMsg('Sticker set ' . $sticker_set_name . ' does not exist yet, creating it');
                $add_sticker_or_create_sticker_set_data['title'] = $from->getFirstName() . 's Personal Stickerpack';
                $create_sticker_set_response = Request::createNewStickerSet($add_sticker_or_create_sticker_set_data);
                if (!$create_sticker_set_response->isOk()) {
                    $this->errMsg('Unable to create sticker set ' . $sticker_set_name . ': ' . $create_sticker_set_response->getDescription());
                    return $this->sendReply($chat_id, $message_id, 'Unable to create sticker set ' . $sticker_set_name . ': ' . $create_sticker_set_response->getDescription());
                }
            }

            //Get the personal sticker set. If that fails, give the user a link instead.
            $this->logMsg('Retrieving stickers in sticker set ' . $sticker_set_name);
            $get_sticker_set_response = Request::getStickerSet(['name' => $sticker_set_name]);
            if (!$get_sticker_set_response->isOk()) {
                $this->errMsg('Unable to get personal sticker pack ' . $sticker_set_name . ': ' . $get_sticker_set_response->getDescription());
                return $this->sendReply($chat_id, $message_id, 'Sticker was added successfully, but telegram needs a bit of time. Here is a link instead: https://t.me/addstickers/' . $sticker_set_name);
            }
            
            //Check if the sticker set contains any stickers. If not, give the user a link instead.
            $stickers = $get_sticker_set_response->getResult()->getStickers();
            if ($stickers === null) {
                $this->errMsg('Personal sticker pack ' . $sticker_set_name . ' is empty.');
                return $this->sendReply($chat_id, $message_id, 'Sticker was added successfully, but telegram says the stickerpack is still empty (it needs a bit of time). Here is a link instead: https://t.me/addstickers/' . $sticker_set_name);
            }
            
            //Send sticker back to user to update their pack.
            $new_sticker = $stickers[count($stickers) - 1];
            $new_sticker_file_id = $new_sticker->getFileId();
            $this->logMsg('Sending reply sticker ' . $new_sticker_file_id . ' to ' . $user_id);
            $send_sticker_data = [
                'chat_id' => $chat_id,
                'sticker' => $new_sticker_file_id,
                'reply_to_message_id' => $message_id,
            ];
            return Request::sendSticker($send_sticker_data);
        } finally {
            //Clean up the png file if caching is disabled
            if ($this->configs['cache_uploads'] === false) {
                unlink($png_file_path);
            }
        }
    }
    
    /**
     * Checks if a sticker set with the given name exists.
     *
     * @return bool
     */
    public function stickerSetExists($sticker_set_name)
    {
        try {
            $get_sticker_set_response = Request::getStickerSet(['name' => $sticker_set_name]);
            if (!$get_sticker_set_response->isOk()) {
                return false;
            } else {
                return true;
            }
        } catch (Longman\TelegramBot\Exception\TelegramException $e) {
            $this->errMsg('Error getting sticker set: ' . $e->getMessage());
            return false;
        }
    }
    
    public function logMsg($msg)
    {
        if ($this->configs['log_debug'] === true) {
            file_put_contents($this->configs['log_location'] . '/debug.log', $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
    
    public function errMsg($msg)
    {
        if ($this->configs['log_errors'] === true) {
            file_put_contents($this->configs['log_location'] . '/error.log', $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Sends the given message as a reply.
     *
     * @param string
     * @param string
     * @param string
     * @return \Longman\TelegramBot\Entities\ServerResponse
     */
    public function sendReply($chat_id, $reply_to, $msg)
    {
        $data = [
            'chat_id'             => $chat_id,
            'reply_to_message_id' => $reply_to,
            'text'                => $msg,
        ];
        return Request::sendMessage($data);
    }
}