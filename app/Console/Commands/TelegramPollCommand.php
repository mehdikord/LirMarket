<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Member_Document;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Api;

class TelegramPollCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:poll';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll Telegram bot for new messages';

    protected $telegram;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env file');
            return 1;
        }

        // بررسی اینکه آیا instance دیگه‌ای در حال اجراست
        if ($this->isAnotherInstanceRunning()) {
            $this->error('Another instance of telegram:poll is already running!');
            $this->warn('Please stop the other instance first or wait a few seconds.');
            return 1;
        }

        $this->telegram = new Api($token);
        $this->info('Starting Telegram bot polling...');
        $this->info('Press Ctrl+C to stop');

        // ایجاد lock file
        $lockFile = storage_path('app/telegram_poll.lock');
        file_put_contents($lockFile, getmypid());

        // ثبت signal handler برای cleanup
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'cleanup']);
            pcntl_signal(SIGINT, [$this, 'cleanup']);
        }

        register_shutdown_function(function() use ($lockFile) {
            if (file_exists($lockFile)) {
                unlink($lockFile);
            }
        });

        while (true) {
            try {
                $this->processUpdates();
                sleep(2); // صبر 2 ثانیه قبل از درخواست بعدی

                // بررسی signal ها
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();

                // اگر conflict بود، صبر بیشتر و cache رو پاک کن
                if (strpos($errorMessage, 'Conflict') !== false || strpos($errorMessage, 'terminated by other getUpdates') !== false) {
                    $this->warn('Conflict detected. Clearing cache and waiting...');
                    Cache::forget('telegram_last_update_id');
                    sleep(10);
                } else {
                    $this->error('Error: ' . $errorMessage);
                    sleep(5); // در صورت خطا، 5 ثانیه صبر کن
                }
            }
        }
    }

    protected function isAnotherInstanceRunning()
    {
        $lockFile = storage_path('app/telegram_poll.lock');

        if (!file_exists($lockFile)) {
            return false;
        }

        $pid = (int) file_get_contents($lockFile);

        // بررسی اینکه process هنوز زنده هست
        if ($pid > 0) {
            // در Linux/Unix
            if (function_exists('posix_kill')) {
                if (posix_kill($pid, 0)) {
                    return true; // Process هنوز زنده هست
                }
            } else {
                // Fallback: استفاده از ps
                $result = shell_exec("ps -p {$pid} 2>/dev/null");
                if ($result && strpos($result, (string)$pid) !== false) {
                    return true;
                }
            }
        }

        // اگر process وجود نداره، lock file رو پاک کن
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }

        return false;
    }

    public function cleanup()
    {
        $lockFile = storage_path('app/telegram_poll.lock');
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        exit(0);
    }

    protected function processUpdates()
    {
        $lastUpdateId = Cache::get('telegram_last_update_id', 0);

        $updates = $this->telegram->getUpdates([
            'offset' => $lastUpdateId + 1,
            'timeout' => 10,
        ]);

        $newLastUpdateId = $lastUpdateId;

        foreach ($updates as $update) {
            $updateId = $update->getUpdateId();

            // اول callback query رو چک کن (اولویت بالاتر)
            $callbackQuery = $update->getCallbackQuery();
            if ($callbackQuery) {
                $this->info("DEBUG: Callback query detected - Data: " . $callbackQuery->getData());
                $this->handleCallbackQuery($callbackQuery);
                // بعد از handle کردن callback query، به update بعدی برو
                if ($updateId > $newLastUpdateId) {
                    $newLastUpdateId = $updateId;
                }
                continue;
            }

            // سپس پیام‌ها رو پردازش کن
            $message = $update->getMessage();
            if ($message) {
                $chat = $message->getChat();
                $chatId = $chat->getId();
                $username = $chat->getUsername() ?? $chat->getFirstName();

                // بررسی state کاربر (منتظر تصویر تایید حساب)
                $userState = Cache::get("telegram_user_state_{$chatId}");

                // بررسی اینکه آیا پیام شامل تصویر است (photo یا document)
                $photoCheck = $message->getPhoto();
                $documentCheck = $message->getDocument();

                $this->info("DEBUG: Photo check - Type: " . gettype($photoCheck));
                if (is_array($photoCheck)) {
                    $this->info("DEBUG: Photo is array with " . count($photoCheck) . " elements");
                } elseif ($photoCheck !== null) {
                    $this->info("DEBUG: Photo is not null and not array: " . get_class($photoCheck));
                }

                $hasPhoto = false;
                if ($photoCheck !== null) {
                    if (is_array($photoCheck) && count($photoCheck) > 0) {
                        $hasPhoto = true;
                    } elseif (is_object($photoCheck) && method_exists($photoCheck, 'toArray')) {
                        // ممکن است Collection باشد
                        $photoArray = $photoCheck->toArray();
                        if (is_array($photoArray) && count($photoArray) > 0) {
                            $hasPhoto = true;
                        }
                    }
                }

                $hasDocument = $documentCheck && is_object($documentCheck);
                $hasImage = $hasPhoto || $hasDocument;

                $this->info("DEBUG: hasPhoto: " . ($hasPhoto ? 'true' : 'false') . ", hasDocument: " . ($hasDocument ? 'true' : 'false') . ", hasImage: " . ($hasImage ? 'true' : 'false'));

                if ($userState === 'waiting_for_verification_image') {
                    // کاربر در حال ارسال تصویر تایید حساب است
                    if ($hasImage) {
                        // پیام شامل تصویر است - پردازش کن
                        $this->handleVerificationImage($message, $chatId);
                    } elseif ($message->has('text')) {
                        // پیام شامل text است اما تصویر نیست - پیام خطا بده
                        $this->info("User in waiting state but sent text instead of image");
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید. (فرمت‌های پشتیبانی شده: PNG، JPG، JPEG، GIF، WEBP، BMP)",
                        ]);
                    } else {
                        // پیام شامل هیچکدام نیست - پیام خطا بده
                        $this->info("User in waiting state but message has no image or text");
                        $this->telegram->sendMessage([
                            'chat_id' => $chatId,
                            'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید.",
                        ]);
                    }
                } elseif ($message->has('text')) {
                    $text = $message->getText();
                    $this->info("Received message from {$username}: {$text}");

                    // بررسی دستور /start
                    if ($text === '/start') {
                        $this->handleStartCommand($chat, $chatId);
                    }
                }
            }

            if ($updateId > $newLastUpdateId) {
                $newLastUpdateId = $updateId;
            }
        }

        if ($newLastUpdateId > $lastUpdateId) {
            Cache::forever('telegram_last_update_id', $newLastUpdateId);
        }
    }

    protected function handleStartCommand($chat, $chatId)
    {
        $telegramId = (string) $chatId;

        // چک کردن اینکه آیا کاربر قبلا وجود داشته
        $member = Member::where('telegram_id', $telegramId)->first();
        $isNewMember = false;

        if (!$member) {
            // کاربر جدید - دریافت و ذخیره اطلاعات
            $memberData = [
                'telegram_id' => $telegramId,
                'name' => trim(
                    ($chat->getFirstName() ?? '') . ' ' .
                    ($chat->getLastName() ?? '')
                ),
                'telegram_username' => $chat->getUsername(),
            ];

            // دریافت اطلاعات بیشتر از Telegram API
            try {
                $userProfile = $this->telegram->getChat(['chat_id' => $chatId]);

                if ($userProfile) {
                    $memberData['name'] = trim(
                        ($userProfile->getFirstName() ?? '') . ' ' .
                        ($userProfile->getLastName() ?? '')
                    );
                    $memberData['telegram_username'] = $userProfile->getUsername();
                }
            } catch (\Exception $e) {
                $this->warn('Could not fetch additional user info: ' . $e->getMessage());
            }

            // ذخیره کاربر جدید
            $member = Member::create($memberData);
            $isNewMember = true;

            // ارسال پیام خوش‌آمدگویی
            $userName = trim($member->name) ?: $chat->getFirstName() ?: 'کاربر';
            $welcomeMessage = "{$userName} گرامی به ربات لیر مارکت خوش آمدید 🌹";

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $welcomeMessage,
            ]);

            $this->info("New member registered: {$userName} (ID: {$telegramId})");

            // ارسال پیام تایید حساب برای کاربر جدید
            $this->sendVerificationMessage($chatId);
        } else {
            // کاربر موجود - بررسی وضعیت تایید
            if (!$member->is_verified) {
                // کاربر تایید نشده - ارسال پیام تایید حساب
                $this->sendVerificationMessage($chatId);
            } else {
                // کاربر تایید شده - نمایش منو اصلی
                $this->showMainMenu($chatId);
            }
        }
    }

    protected function sendVerificationMessage($chatId)
    {
        $message = "برای استفاده از امکانات لیر مارکت شما ابتدا باید حساب کاربری خود را تایید کنید. برای تایید حساب روی دکمه تایید حساب بزنید.";

        // ایجاد دکمه inline
        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'تایید حساب',
                        'callback_data' => 'verify_account'
                    ]
                ]
            ]
        ];

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function showMainMenu($chatId)
    {
        $message = "منو اصلی:";

        // ایجاد دکمه‌های منو اصلی
        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => 'تبدیل لیر به ریال',
                        'callback_data' => 'lir_to_rial'
                    ]
                ],
                [
                    [
                        'text' => 'تبدیل ریال به لیر',
                        'callback_data' => 'rial_to_lir'
                    ]
                ]
            ]
        ];

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    protected function handleCallbackQuery($callbackQuery)
    {
        $data = $callbackQuery->getData();
        $queryId = $callbackQuery->getId();

        // دریافت chat ID از callback query
        $chatId = null;
        try {
            $message = $callbackQuery->getMessage();
            if ($message) {
                $chatId = $message->getChat()->getId();
            } else {
                // اگر message نبود، از from استفاده کن
                $from = $callbackQuery->getFrom();
                if ($from) {
                    $chatId = $from->getId();
                }
            }
        } catch (\Exception $e) {
            $this->error("Error getting chat ID from callback query: " . $e->getMessage());
            return;
        }

        if (!$chatId) {
            $this->error("Could not determine chat ID from callback query");
            return;
        }

        $this->info("Processing callback query: {$data} from chat ID: {$chatId}");
        $this->info("Callback data type: " . gettype($data));
        $this->info("Callback data value: '" . $data . "'");
        $this->info("Callback data length: " . strlen($data));

        // پاسخ به callback query (برای حذف loading state)
        try {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $queryId,
            ]);
            $this->info("Callback query answered successfully");
        } catch (\Exception $e) {
            $this->warn("Error answering callback query: " . $e->getMessage());
        }

        // بررسی دقیق callback data
        $dataTrimmed = trim($data);
        $this->info("Comparing: '{$dataTrimmed}' === 'verify_account' = " . ($dataTrimmed === 'verify_account' ? 'true' : 'false'));

        // استفاده از if-else به جای switch برای اطمینان از match شدن
        if ($dataTrimmed === 'verify_account') {
            $this->info("=== CASE MATCHED: verify_account ===");
            $this->info("Handling verify_account request for chat ID: {$chatId}");
            $this->handleVerifyAccountRequest($chatId);
            $this->info("=== END CASE verify_account ===");
        } elseif ($dataTrimmed === 'lir_to_rial') {
            // فعلا کاری انجام نمی‌دهیم
            $this->info("User clicked lir_to_rial button (ID: {$chatId})");
        } elseif ($dataTrimmed === 'rial_to_lir') {
            // فعلا کاری انجام نمی‌دهیم
            $this->info("User clicked rial_to_lir button (ID: {$chatId})");
        } else {
            $this->warn("No case matched for callback data: '{$dataTrimmed}'");
            $this->warn("Raw data: '" . $data . "'");
            $this->warn("Data hex: " . bin2hex($data));
        }
    }

    protected function handleVerifyAccountRequest($chatId)
    {
        try {
            $this->info("=== Starting handleVerifyAccountRequest ===");
            $this->info("Chat ID: {$chatId}");
            $this->info("Chat ID type: " . gettype($chatId));

            // تنظیم state برای انتظار تصویر
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_verification_image', 3600); // 1 ساعت
            $this->info("State set to waiting_for_verification_image");

            // بررسی state
            $checkState = Cache::get("telegram_user_state_{$chatId}");
            $this->info("State verification: " . ($checkState === 'waiting_for_verification_image' ? 'OK' : 'FAILED'));

            $message = "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید.";
            $this->info("Preparing to send message to chat ID: {$chatId}");

            // ارسال پیام
            $result = $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
            ]);

            $this->info("Message sent successfully!");
            $this->info("Result type: " . gettype($result));
            if (is_object($result)) {
                $this->info("Result class: " . get_class($result));
            }
            $this->info("User requested verification (ID: {$chatId}) - waiting for image");
            $this->info("=== End handleVerifyAccountRequest ===");
        } catch (\Exception $e) {
            $this->error("=== ERROR in handleVerifyAccountRequest ===");
            $this->error("Error message: " . $e->getMessage());
            $this->error("Error code: " . $e->getCode());
            $this->error("Error file: " . $e->getFile() . ":" . $e->getLine());
            $this->error("Stack trace: " . $e->getTraceAsString());
            $this->error("=== END ERROR ===");
        }
    }

    protected function handleVerificationImage($message, $chatId)
    {
        $photo = $message->getPhoto();
        $document = $message->getDocument();
        $fileId = null;
        $fileExtension = 'jpg'; // پیش‌فرض
        $mimeType = null;

        $this->info("=== DEBUG: handleVerificationImage called ===");
        $this->info("Photo received. Type: " . gettype($photo));
        $this->info("Document received. Type: " . gettype($document));

        // تابع کمکی برای استخراج file_id از یک object یا array
        $extractFileId = function($item) use (&$fileId) {
            if (is_object($item)) {
                // روش‌های مختلف برای استخراج file_id
                if (method_exists($item, 'getFileId')) {
                    return $item->getFileId();
                } elseif (method_exists($item, 'get')) {
                    return $item->get('file_id') ?? $item->get('fileId') ?? null;
                } elseif (isset($item->file_id)) {
                    return $item->file_id;
                } elseif (isset($item->fileId)) {
                    return $item->fileId;
                } elseif (property_exists($item, 'file_id')) {
                    try {
                        return $item->file_id;
                    } catch (\Exception $e) {
                        return null;
                    }
                } elseif (property_exists($item, 'fileId')) {
                    try {
                        return $item->fileId;
                    } catch (\Exception $e) {
                        return null;
                    }
                }
            } elseif (is_array($item)) {
                return $item['file_id'] ?? $item['fileId'] ?? null;
            }
            return null;
        };

        // تبدیل photo به آرایه
        $photoArray = null;
        if ($photo !== null) {
            if (is_array($photo)) {
                $photoArray = $photo;
                $this->info("Photo is array with " . count($photo) . " elements");
            } elseif (is_object($photo)) {
                // اگر photo خودش مستقیماً file_id دارد (مثلاً یک PhotoSize object)
                $directFileId = $extractFileId($photo);
                if ($directFileId) {
                    $fileId = $directFileId;
                    $this->info("Photo object has direct file_id: {$fileId}");
                } else {
                    // سعی کن photo را به array تبدیل کن
                    if (method_exists($photo, 'toArray')) {
                        $photoArray = $photo->toArray();
                        $this->info("Photo converted to array using toArray(), " . count($photoArray) . " elements");
                    } elseif (method_exists($photo, 'all')) {
                        $photoArray = $photo->all();
                        $this->info("Photo converted to array using all(), " . count($photoArray) . " elements");
                    } elseif ($photo instanceof \Illuminate\Support\Collection) {
                        $photoArray = $photo->toArray();
                        $this->info("Photo is Illuminate Collection, converted to array, " . count($photoArray) . " elements");
                    } elseif ($photo instanceof \Traversable || is_iterable($photo)) {
                        $photoArray = [];
                        foreach ($photo as $item) {
                            $photoArray[] = $item;
                        }
                        $this->info("Photo is iterable, converted to array, " . count($photoArray) . " elements");
                    } else {
                        // اگر هیچکدام کار نکرد، object را به عنوان یک element در آرایه قرار بده
                        $photoArray = [$photo];
                        $this->info("Photo object wrapped in array (single element): " . get_class($photo));
                    }
                }
            }
        }

        // اگر file_id از photo object مستقیماً استخراج شد، از آن استفاده کن
        if ($fileId) {
            $this->info("Using direct file_id from photo object: {$fileId}");
        }
        // در غیر این صورت، از photoArray استفاده کن
        elseif ($photoArray && is_array($photoArray) && count($photoArray) > 0) {
            $this->info("Processing photo array with " . count($photoArray) . " elements");

            // پیدا کردن بزرگترین سایز تصویر
            $maxSize = 0;
            $maxPhotoSize = null;

            foreach ($photoArray as $size) {
                $currentFileSize = 0;

                if (is_object($size)) {
                    // سعی کن file size را پیدا کن
                    if (method_exists($size, 'getFileSize')) {
                        $currentFileSize = $size->getFileSize() ?? 0;
                    } elseif (method_exists($size, 'getWidth') && method_exists($size, 'getHeight')) {
                        $currentFileSize = ($size->getWidth() ?? 0) * ($size->getHeight() ?? 0);
                    }
                } elseif (is_array($size)) {
                    $currentFileSize = $size['file_size'] ?? $size['fileSize'] ??
                                      (($size['width'] ?? 0) * ($size['height'] ?? 0));
                }

                if ($currentFileSize > $maxSize) {
                    $maxSize = $currentFileSize;
                    $maxPhotoSize = $size;
                }
            }

            // اگر بزرگترین پیدا نشد، از آخرین element استفاده کن
            if (!$maxPhotoSize && count($photoArray) > 0) {
                $maxPhotoSize = end($photoArray);
            }

            // استخراج file_id از بزرگترین photo size
            if ($maxPhotoSize) {
                $extractedFileId = $extractFileId($maxPhotoSize);
                if ($extractedFileId) {
                    $fileId = $extractedFileId;
                    $fileExtension = 'jpg'; // تصاویر photo معمولاً jpg هستند
                    $this->info("Photo file ID obtained from largest size: {$fileId}");
                } else {
                    $this->error("Could not extract file_id from photo size");
                    if (is_object($maxPhotoSize)) {
                        $this->error("Photo size object class: " . get_class($maxPhotoSize));
                        $this->error("Available methods: " . implode(', ', get_class_methods($maxPhotoSize)));
                    } elseif (is_array($maxPhotoSize)) {
                        $this->error("Photo size array keys: " . implode(', ', array_keys($maxPhotoSize)));
                    }
                }
            }
        }

        // اگر هنوز file_id پیدا نشد و document وجود دارد، از document استفاده کن
        if (!$fileId && $document && is_object($document)) {
            $this->info("Processing document as image");

            $mimeType = $document->getMimeType();
            $fileName = $document->getFileName();

            $this->info("Document MIME type: " . ($mimeType ?? 'null'));
            $this->info("Document file name: " . ($fileName ?? 'null'));

            // لیست فرمت‌های تصویر پشتیبانی شده
            $allowedImageMimeTypes = [
                'image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp',
                'image/bmp', 'image/x-ms-bmp', 'image/tiff', 'image/tif', 'image/svg+xml',
            ];

            $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'svg'];

            // بررسی extension از نام فایل
            $isImageByExtension = false;
            if ($fileName) {
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if ($ext && in_array($ext, $allowedExtensions)) {
                    $isImageByExtension = true;
                    $fileExtension = $ext;
                    $this->info("Image detected by extension: {$ext}");
                }
            }

            // بررسی MIME type
            $isImageByMimeType = false;
            if ($mimeType && in_array(strtolower($mimeType), $allowedImageMimeTypes)) {
                $isImageByMimeType = true;
                $this->info("Image detected by MIME type: {$mimeType}");

                // تعیین extension از MIME type
                $mimeToExt = [
                    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
                    'image/gif' => 'gif', 'image/webp' => 'webp', 'image/bmp' => 'bmp',
                    'image/x-ms-bmp' => 'bmp', 'image/tiff' => 'tiff', 'image/tif' => 'tiff',
                    'image/svg+xml' => 'svg',
                ];
                if (isset($mimeToExt[strtolower($mimeType)])) {
                    $fileExtension = $mimeToExt[strtolower($mimeType)];
                }
            }

            // اگر MIME type یا extension نشان دهد که تصویر است، قبول کن
            if ($isImageByMimeType || $isImageByExtension) {
                try {
                    $fileId = $document->getFileId();
                    $this->info("Document accepted as image. File ID: {$fileId}, Extension: {$fileExtension}");
                } catch (\Exception $e) {
                    $this->error("Error getting file_id from document: " . $e->getMessage());
                }
            } else {
                // اگر MIME type و extension مشخص نبود، فایل را دانلود کن و بررسی کن
                $this->info("Document MIME type and extension not clear. Downloading to verify...");
                try {
                    $tempFileId = $document->getFileId();
                    $tempFile = $this->telegram->getFile(['file_id' => $tempFileId]);
                    $tempPath = storage_path('app/temp');
                    if (!file_exists($tempPath)) {
                        mkdir($tempPath, 0755, true);
                    }
                    $tempDownloadedFile = $this->telegram->downloadFile($tempFile, $tempPath);

                    // بررسی با getimagesize
                    if (function_exists('getimagesize')) {
                        $imageInfo = @getimagesize($tempDownloadedFile);
                        if ($imageInfo !== false) {
                            // فایل یک تصویر معتبر است
                            $fileId = $tempFileId;
                            $detectedMime = $imageInfo['mime'];
                            $mimeToExt = [
                                'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
                                'image/webp' => 'webp', 'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp',
                                'image/tiff' => 'tiff',
                            ];
                            if (isset($mimeToExt[$detectedMime])) {
                                $fileExtension = $mimeToExt[$detectedMime];
                            }
                            $this->info("Document verified as image by getimagesize. MIME: {$detectedMime}, Extension: {$fileExtension}");
                            // حذف فایل موقت
                            if (file_exists($tempDownloadedFile)) {
                                unlink($tempDownloadedFile);
                            }
                        } else {
                            // فایل تصویر نیست
                            if (file_exists($tempDownloadedFile)) {
                                unlink($tempDownloadedFile);
                            }
                            $this->info("Document is not a valid image (verified by getimagesize)");
                        }
                    } else {
                        // اگر getimagesize موجود نبود، document را قبول کن
                        $fileId = $tempFileId;
                        $this->info("getimagesize not available, accepting document as-is");
                        if (file_exists($tempDownloadedFile)) {
                            unlink($tempDownloadedFile);
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("Error verifying document: " . $e->getMessage());
                }
            }
        }

        // اگر تصویر پیدا نشد
        if (!$fileId) {
            $this->info("No valid image found in message");
            $this->info("Photo: " . ($photo ? 'exists' : 'null'));
            $this->info("Document: " . ($document ? 'exists' : 'null'));
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید. (فرمت‌های پشتیبانی شده: PNG، JPG، JPEG، GIF، WEBP، BMP)",
            ]);
            return;
        }

        try {
            $this->info("File ID obtained: {$fileId}, Extension: {$fileExtension}");

            // دریافت اطلاعات فایل از Telegram
            $file = $this->telegram->getFile(['file_id' => $fileId]);
            $filePath = $file->getFilePath();
            $this->info("File path from Telegram: {$filePath}");

            // دانلود فایل از Telegram
            $tempPath = storage_path('app/temp');
            if (!file_exists($tempPath)) {
                mkdir($tempPath, 0755, true);
            }

            $downloadedFile = $this->telegram->downloadFile($file, $tempPath);
            $this->info("File downloaded to: {$downloadedFile}");

            // بررسی اینکه فایل دانلود شده واقعاً یک تصویر است (برای امنیت بیشتر)
            if (function_exists('getimagesize')) {
                $imageInfo = @getimagesize($downloadedFile);
                if ($imageInfo === false) {
                    $this->error("Downloaded file is not a valid image");
                    if (file_exists($downloadedFile)) {
                        unlink($downloadedFile);
                    }
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "فایل ارسال شده یک تصویر معتبر نیست. لطفا یک فایل تصویری ارسال کنید.",
                    ]);
                    return;
                }
                // اگر extension از MIME type فایل دانلود شده متفاوت بود، آن را اصلاح کن
                $detectedMime = $imageInfo['mime'];
                $this->info("Image verified. Detected MIME: {$detectedMime}");
                $mimeToExt = [
                    'image/png' => 'png',
                    'image/jpeg' => 'jpg',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'image/bmp' => 'bmp',
                    'image/x-ms-bmp' => 'bmp',
                    'image/tiff' => 'tiff',
                ];
                if (isset($mimeToExt[$detectedMime])) {
                    $fileExtension = $mimeToExt[$detectedMime];
                    $this->info("Extension updated to: {$fileExtension}");
                }
            }

            // ایجاد مسیر ذخیره‌سازی با extension صحیح
            $storagePath = "members/verification";
            $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
            $fullPath = "{$storagePath}/{$fileName}";

            // اطمینان از وجود دایرکتوری
            $fullStoragePath = storage_path("app/public/{$storagePath}");
            if (!file_exists($fullStoragePath)) {
                mkdir($fullStoragePath, 0755, true);
            }

            // خواندن محتوای فایل دانلود شده
            $fileContent = file_get_contents($downloadedFile);

            // ذخیره فایل در storage
            Storage::disk('public')->put($fullPath, $fileContent);

            // حذف فایل موقت
            if (file_exists($downloadedFile)) {
                unlink($downloadedFile);
            }

            // پیدا کردن member
            $member = Member::where('telegram_id', (string) $chatId)->first();

            if ($member) {
                // ساخت URL فایل
                $fileUrl = url('storage/' . $fullPath);

                // ذخیره در جدول member_documents
                Member_Document::create([
                    'member_id' => $member->id,
                    'name' => 'تصویر تائید حساب',
                    'file_type' => 'verification',
                    'file_path' => $fullPath,
                    'file_url' => $fileUrl,
                ]);

                // حذف state
                Cache::forget("telegram_user_state_{$chatId}");

                // ارسال پیام تایید
                $confirmMessage = "تصویر شما برای تایید حساب به مدیریت ارسال شد از صبر و شکیبایی شما متشکریم\nدر صورت تایید حساب ربات به شما پیام میدهد";

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $confirmMessage,
                ]);

                $this->info("Verification image saved for member ID: {$member->id}, Format: {$fileExtension}");
            }

        } catch (\Exception $e) {
            $this->error("Error handling verification image: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی در ارسال تصویر رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }
}
