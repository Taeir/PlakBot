<?php
namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\Update;
use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Exception\TelegramException;
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
     * @var bool
     */
    protected $valid;
    
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
        $this->valid = ($this->configs !== false);
    }
    
    /**
     * Execute command
     *
     * @return \Longman\TelegramBot\Entities\ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute()
    {
        //Check config
        if (!$this->valid) {
            return $this->sendReply('Config file not found!');
        }

        $message = $this->getMessage();
        $from    = $message->getFrom();
        $user_id = $from->getId();

        //If not sticker, fail quickly.
        if ($message->getType() !== 'sticker') {
            return $this->sendReply('Please send a sticker.');
        }
        
        $original_sticker  = $message->getSticker();
        $emojis            = $original_sticker->getEmoji();
        $original_file_id  = $original_sticker->getFileId();
        $original_set_name = $original_sticker->getSetName();
        $this->logMsg('Received sticker [id: ' . $original_file_id . '; emojis: ' . $emojis . '; set: ' . $original_set_name . '] from user ' . $user_id . ' (' . $from->getFirstName() . ')');
        
        //Check if already in the pack.
        $sticker_set_name  = 'user_' . $user_id . '_by_' . $this->telegram->getBotUsername();
        if ($sticker_set_name === $original_set_name) {
            return $this->deleteSticker($original_file_id);
        }
        
        //Send that we are uploading a photo
        Request::sendChatAction([
            'chat_id' => $message->getChat()->getId(),
            'action' => 'upload_photo'
        ]);

        $png_file_path = $this->downloadAndConvert($original_file_id);
        if (!is_string($png_file_path)) {
            return $png_file_path;
        }

        $add_result = $this->addStickerOrCreateSet($png_file_path, $emojis, $sticker_set_name, $from);
        if ($add_result !== true) {
            return $add_result;
        }

        $new_sticker_file_id = $this->getNewSticker($sticker_set_name);
        if (!is_string($new_sticker_file_id)) {
            return $new_sticker_file_id;
        }

        $this->logMsg('Sending reply sticker ' . $new_sticker_file_id . ' to ' . $user_id);
        $send_sticker_data = [
            'chat_id' => $message->getChat()->getId(),
            'sticker' => $new_sticker_file_id,
            'reply_to_message_id' => $message->getMessageId(),
        ];
        return Request::sendSticker($send_sticker_data);
    }


    /**
     * Deletes the sticker with the given file id.
     *
     * @param string $original_file_id
     * @return ServerResponse
     */
    protected function deleteSticker(string $original_file_id): ServerResponse
    {
        $response = Request::deleteStickerFromSet(['sticker' => $original_file_id]);
        if (!$response->isOk()) {
            $this->errMsg('deleteStickerFromSet(' . $original_file_id . ') failed: ' . $response->getDescription());
            return $this->sendReply('Deletion of sticker failed: ' . $response->getDescription());
        }

        $this->logMsg('Deleted ' . $original_file_id . ' from set');
        return $this->sendReply('Deleted sticker from pack.');
    }

    /**
     * Downloads the given file and then converts it. If the file is in the cache, that file is used instead.
     *
     * @param string $original_file_id
     * @return string|ServerResponse
     */
    protected function downloadAndConvert(string $original_file_id)
    {
        $png_file_path  = $this->telegram->getUploadPath() . '/' . $original_file_id . '.png';
        if (!file_exists($png_file_path)) {
            //Download
            $this->logMsg('Downloading ' . $original_file_id . ' for chat ' . $this->getMessage()->getChat()->getId());
            $webp_file_path = $this->downloadSticker($original_file_id);
            if (!is_string($webp_file_path)) {
                return $webp_file_path;
            }

            //Convert
            $this->logMsg('Converting ' . $original_file_id. ' (' . $webp_file_path . ')');
            $convert_result = $this->convertImage($webp_file_path, $png_file_path);
            if ($convert_result !== true) {
                return $convert_result;
            }
        } else {
            $this->logMsg('Skipped download and conversion, using ' . $original_file_id . '.png from cache');
        }

        return $png_file_path;
    }

    /**
     * Downloads the given file id.
     *
     * @param string $original_file_id
     * @return string|ServerResponse
     */
    protected function downloadSticker(string $original_file_id)
    {
        $getfile_response = Request::getFile(['file_id' => $original_file_id]);
        if (!$getfile_response->isOk()) {
            $this->errMsg('getFile(' . $original_file_id . ') failed: ' . $getfile_response->getDescription());
            return $this->sendReply('Unable to download this sticker: ' . $getfile_response->getDescription());
        }

        //Download original file
        $webp_file = $getfile_response->getResult();
        try {
            $download_success = Request::downloadFile($webp_file);
        } catch (TelegramException $e) {
            $download_success = $e->getMessage();
        }

        if ($download_success !== true) {
            $this->errMsg('downloadFile(' . $original_file_id . ') failed: ' . $download_success);
            return $this->sendReply('Unable to download this sticker: ' . $download_success);
        }

        return $this->telegram->getDownloadPath() . '/' . $webp_file->getFilePath();
    }

    /**
     * Converts the given webp image to a png image at the given png path.
     *
     * @param string $webp_file_path
     * @param string $png_file_path
     * @return bool|ServerResponse
     */
    protected function convertImage(string $webp_file_path, string $png_file_path)
    {
        if ($this->configs['cloudconvert']) {
            return $this->convertWithCloudConvert($webp_file_path, $png_file_path);
        } else {
            return $this->convertNative($webp_file_path, $png_file_path);
        }
    }

    /**
     * Converts the given webp file to the given png file using native PHP.
     *
     * @param string $webp_file_path
     * @param string $png_file_path
     * @return bool
     */
    protected function convertNative(string $webp_file_path, string $png_file_path): bool
    {
        $im = null;
        try {
            $im = imagecreatefromwebp($webp_file_path);
            imagepng($im, $png_file_path);
            return true;
        } finally {
            //Cleanup
            if ($im !== null) imagedestroy($im);

            unlink($webp_file_path);
        }
    }

    /**
     * Converts the given webp file to the given png file using couldconvert.
     *
     * @param string $webp_file_path
     * @param string $png_file_path
     * @return bool|ServerResponse
     */
    protected function convertWithCloudConvert(string $webp_file_path, string $png_file_path)
    {
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
            $this->logMsg('Converted ' . $webp_file_path . ' to ' . $png_file_path);
            return true;
        } catch (\CloudConvert\Exceptions\InvalidParameterException $e) {
            $this->errMsg('Conversion failed: configuration is invalid! ' . $e->getMessage());
            return $this->sendReply('Sticker conversion failed: configuration is invalid!');
        } catch (\CloudConvert\Exceptions\ApiBadRequestException $e) {
            $this->errMsg('Conversion failed: ApiBadRequestException: ' . $e->getMessage());
            return $this->sendReply('Sticker conversion failed: ' . $e->getMessage());
        } catch (\CloudConvert\Exceptions\ApiConversionFailedException $e) {
            $this->errMsg('Conversion failed: ApiConversionFailedException (broken input file?): ' . $e->getMessage());
            return $this->sendReply('This sticker cannot be converted: ' . $e->getMessage());
        } catch (\CloudConvert\Exceptions\ApiTemporaryUnavailableException $e) {
            $this->errMsg('Conversion failed: API temporarily unavailable: ' . $e->getMessage() . PHP_EOL . ' Retry after ' . $e->retryAfter);
            return $this->sendReply('Sticker conversion failed: image conversion API temporarily unavailable: ' . $e->getMessage() . '. Retry in ' . $e->retryAfter . ' seconds');
        } catch (\CloudConvert\Exceptions\ApiException $e) {
            $this->errMsg('Conversion failed for an unknown reason: ' . $e->getMessage());
            return $this->sendReply('Conversion failed: unable to convert sticker, cannot reach image conversion API.');
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            $this->errMsg('Conversion failed: GuzzleException (Unable to contact CloudConvert API?): ' . $e->getMessage());
            return $this->sendReply('Sticker conversion failed: cannot reach image conversion API.');
        } finally {
            //Cleanup and delete file
            if (is_resource($webp_file_handle)) {
                fclose($webp_file_handle);
            }

            unlink($webp_file_path);
        }
    }

    /**
     * Checks if a sticker set with the given name exists.
     *
     * @param string $sticker_set_name
     * @return bool
     */
    protected function stickerSetExists(string $sticker_set_name): bool
    {
        try {
            $get_sticker_set_response = Request::getStickerSet(['name' => $sticker_set_name]);
            if (!$get_sticker_set_response->isOk()) {
                sleep(2);
                return false;
            } else {
                return true;
            }
        } catch (TelegramException $e) {
            $this->errMsg('Error getting sticker set: ' . $e->getMessage());
            sleep(2);
            return false;
        }
    }

    /**
     * Adds the given file as a sticker with the given emojis to the set with the given name for the given user.
     * If this set does not exist yet, it is created.
     *
     * @param string $png_file_path
     * @param string $emojis
     * @param string $sticker_set_name
     * @param User $user
     * @return bool|ServerResponse
     */
    protected function addStickerOrCreateSet(string $png_file_path, string $emojis, string $sticker_set_name, User $user)
    {
        try {
            $fh = Request::encodeFile($png_file_path);
        } catch (TelegramException $e) {
            $this->errMsg('Unable to open file ' . $png_file_path . ': ' . $e->getMessage());
            return $this->sendReply('Unable to add sticker to sticker set ' . $sticker_set_name . ': cannot open png file.');
        }

        try {
            $data = [
                'user_id'     => $user->getId(),
                'name'        => $sticker_set_name,
                'png_sticker' => $fh,
            ];
            if ($emojis != "" && $emojis != null) $data['emojis'] = $emojis;
            $this->logMsg('Checking if sticker set ' . $sticker_set_name . ' already exists');
            if ($this->stickerSetExists($sticker_set_name)) {
                //Add sticker to existing set
                $this->logMsg('Sticker set ' . $sticker_set_name . ' exists, adding sticker to set');
                $response = Request::addStickerToSet($data);
                if (!$response->isOk()) {
                    $this->errMsg('Unable to add sticker to set ' . $sticker_set_name . ': ' . $response->getDescription());
                    return $this->sendReply('Unable to add sticker to set ' . $sticker_set_name . ': ' . $response->getDescription());
                }
            } else {
                //Create new sticker set
                $this->logMsg('Sticker set ' . $sticker_set_name . ' does not exist yet, creating it');
                $data['title'] = $user->getFirstName() . 's Personal Stickerpack';
                $response = Request::createNewStickerSet($data);

                if (!$response->isOk()) {
                    $this->errMsg('Unable to create sticker set ' . $sticker_set_name . ': ' . $response->getDescription());
                    return $this->sendReply('Unable to create sticker set ' . $sticker_set_name . ': ' . $response->getDescription());
                }
            }

            return true;
        } finally {
            fclose($fh);

            //Clean up the png file if caching is disabled
            if ($this->configs['cache_uploads'] === false) {
                unlink($png_file_path);
            }
        }
    }

    /**
     * Gets file id of the last sticker from the given sticker set.
     *
     * @param string $sticker_set_name
     * @return string|ServerResponse
     */
    protected function getNewSticker(string $sticker_set_name)
    {
        $this->logMsg('Retrieving stickers in sticker set ' . $sticker_set_name);
        $response = Request::getStickerSet(['name' => $sticker_set_name]);
        if (!$response->isOk()) {
            $this->errMsg('Unable to get personal sticker pack ' . $sticker_set_name . ': ' . $response->getDescription());
            return $this->sendReply('Sticker was added successfully, but telegram needs a bit of time. Here is a link instead: https://t.me/addstickers/' . $sticker_set_name);
        }

        //Check if the sticker set contains any stickers. If not, give the user a link instead.
        $stickers = $response->getResult()->getStickers();
        if ($stickers === null) {
            $this->errMsg('Personal sticker pack ' . $sticker_set_name . ' is empty.');
            return $this->sendReply('Sticker was added successfully, but telegram says the stickerpack is still empty (it needs a bit of time). Here is a link instead: https://t.me/addstickers/' . $sticker_set_name);
        }

        $new_sticker = $stickers[count($stickers) - 1];
        return $new_sticker->getFileId();
    }

    /**
     * @param string $msg
     */
    public function logMsg(string $msg)
    {
        if ($this->configs['log_debug'] === true) {
            file_put_contents($this->configs['log_location'] . '/debug.log', $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * @param string $msg
     */
    public function errMsg(string $msg)
    {
        if ($this->configs['log_errors'] === true) {
            file_put_contents($this->configs['log_location'] . '/error.log', $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * Sends the given message as a reply.
     *
     * @param string $text
     * @return ServerResponse
     */
    public function sendReply(string $text): ServerResponse
    {
        try {
            $msg = $this->getMessage();
            $data = [
                'chat_id' => $msg->getChat()->getId(),
                'reply_to_message_id' => $msg->getMessageId(),
                'text' => $text,
            ];
            return Request::sendMessage($data);
        } catch (TelegramException $e) {
            $this->errMsg('Failed to send reply to user: ' . $e->getMessage());
        }

        return GenericmessageCommand::fakeResponse();
    }

    /** @noinspection PhpDocMissingThrowsInspection */
    /**
     * Returns a non-ok response.
     *
     * @return ServerResponse
     */
    protected static function fakeResponse(): ServerResponse
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return new ServerResponse(['ok' => false, 'result' => false], null);
    }

    /** @noinspection PhpDocMissingThrowsInspection */
    /**
     * Returns a response wrapping the given error message.
     *
     * @param TelegramException $e
     * @return ServerResponse
     */
    protected static function errorResponse(TelegramException $e): ServerResponse
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        return new ServerResponse([
            'ok' => false,
            'description' => 'TelegramException: ' . $e->getMessage(),
            'error_code' => is_int($e->getCode()) ? $e->getCode() : 0,
        ], null);
    }
}