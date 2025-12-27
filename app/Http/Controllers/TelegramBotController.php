<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Member_Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Api;

class TelegramBotController extends Controller
{
    protected $telegram;

    public function __construct()
    {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            throw new \Exception('TELEGRAM_BOT_TOKEN is not set in .env file');
        }

        $this->telegram = new Api($token);
    }

    public function handle(Request $request)
    {
        try {
            // دریافت آخرین update ID که پردازش شده
            $lastUpdateId = Cache::get('telegram_last_update_id', 0);

            // دریافت آپدیت‌های جدید از تلگرام
            $updates = $this->telegram->getUpdates([
                'offset' => $lastUpdateId + 1,
                'timeout' => 10,
            ]);

            $newLastUpdateId = $lastUpdateId;
            $processed = 0;

            foreach ($updates as $update) {
                $updateId = $update->getUpdateId();

                // اول callback query رو چک کن (اولویت بالاتر)
                $callbackQuery = $update->getCallbackQuery();
                if ($callbackQuery) {
                    $this->handleCallbackQuery($callbackQuery);
                    $processed++;
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

                    // بررسی state کاربر (منتظر تصویر تایید حساب)
                    $userState = Cache::get("telegram_user_state_{$chatId}");

                    // بررسی اینکه آیا پیام شامل تصویر است (photo یا document)
                    $photoCheck = $message->getPhoto();
                    $documentCheck = $message->getDocument();

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
                        } elseif (is_object($photoCheck) && method_exists($photoCheck, 'all')) {
                            $photoArray = $photoCheck->all();
                            if (is_array($photoArray) && count($photoArray) > 0) {
                                $hasPhoto = true;
                            }
                        }
                    }

                    $hasDocument = $documentCheck && is_object($documentCheck);
                    $hasImage = $hasPhoto || $hasDocument;

                    if ($userState === 'waiting_for_verification_image') {
                        // کاربر در حال ارسال تصویر تایید حساب است
                        if ($hasImage) {
                            // پیام شامل تصویر است - پردازش کن
                            $this->handleVerificationImage($message, $chatId);
                            $processed++;
                        } elseif ($message->has('text')) {
                            // پیام شامل text است اما تصویر نیست - پیام خطا بده
                            \Log::info("User in waiting state but sent text instead of image");
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید. (فرمت‌های پشتیبانی شده: PNG، JPG، JPEG، GIF، WEBP، BMP)",
                            ]);
                            $processed++;
                        } else {
                            // پیام شامل هیچکدام نیست - پیام خطا بده
                            \Log::info("User in waiting state but message has no image or text");
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید.",
                            ]);
                            $processed++;
                        }
                    } elseif ($message->has('text')) {
                        $text = $message->getText();

                        // بررسی دستور /start
                        if ($text === '/start') {
                            $this->handleStartCommand($chat, $chatId);
                            $processed++;
                        }
                    }
                }

                // به‌روزرسانی آخرین update ID
                if ($updateId > $newLastUpdateId) {
                    $newLastUpdateId = $updateId;
                }
            }

            // ذخیره آخرین update ID در cache
            if ($newLastUpdateId > $lastUpdateId) {
                Cache::forever('telegram_last_update_id', $newLastUpdateId);
            }

            return response()->json([
                'status' => 'success',
                'processed' => $processed,
                'total_updates' => count($updates),
            ]);
        } catch (\Exception $e) {
            \Log::error('Telegram bot error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    protected function handleStartCommand($chat, $chatId)
    {
        $telegramId = (string) $chatId;

        // چک کردن اینکه آیا کاربر قبلا وجود داشته
        $member = Member::where('telegram_id', $telegramId)->first();

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
                \Log::warning('Could not fetch additional user info: ' . $e->getMessage());
            }

            // ذخیره کاربر جدید
            $member = Member::create($memberData);

            // ارسال پیام خوش‌آمدگویی
            $userName = trim($member->name) ?: $chat->getFirstName() ?: 'کاربر';
            $welcomeMessage = "{$userName} گرامی به ربات لیر مارکت خوش آمدید 🌹";

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $welcomeMessage,
            ]);

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
            \Log::error("Error getting chat ID from callback query: " . $e->getMessage());
            return;
        }

        if (!$chatId) {
            \Log::error("Could not determine chat ID from callback query");
            return;
        }

        \Log::info("Processing callback query: {$data} from chat ID: {$chatId}");

        // پاسخ به callback query (برای حذف loading state)
        try {
            $this->telegram->answerCallbackQuery([
                'callback_query_id' => $queryId,
            ]);
        } catch (\Exception $e) {
            \Log::warning("Error answering callback query: " . $e->getMessage());
        }

        switch ($data) {
            case 'verify_account':
                \Log::info("Handling verify_account request for chat ID: {$chatId}");
                $this->handleVerifyAccountRequest($chatId);
                break;

            case 'lir_to_rial':
                // فعلا کاری انجام نمی‌دهیم
                \Log::info("User clicked lir_to_rial button (ID: {$chatId})");
                break;

            case 'rial_to_lir':
                // فعلا کاری انجام نمی‌دهیم
                \Log::info("User clicked rial_to_lir button (ID: {$chatId})");
                break;
        }
    }

    protected function handleVerifyAccountRequest($chatId)
    {
        try {
            \Log::info("Starting handleVerifyAccountRequest for chat ID: {$chatId}");

            // تنظیم state برای انتظار تصویر
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_verification_image', 3600); // 1 ساعت
            \Log::info("State set to waiting_for_verification_image");

            $message = "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید.";
            \Log::info("Preparing to send message to chat ID: {$chatId}");

            $result = $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
            ]);

            \Log::info("Message sent successfully. Result: " . json_encode($result));
            \Log::info("User requested verification (ID: {$chatId}) - waiting for image");
        } catch (\Exception $e) {
            \Log::error("Error in handleVerifyAccountRequest: " . $e->getMessage());
            \Log::error("Error details: " . $e->getFile() . ":" . $e->getLine());
            \Log::error("Stack trace: " . $e->getTraceAsString());
        }
    }

    protected function handleVerificationImage($message, $chatId)
    {
        $photo = $message->getPhoto();
        $document = $message->getDocument();
        $fileId = null;
        $fileExtension = 'jpg'; // پیش‌فرض
        $mimeType = null;

        \Log::info("Photo received. Type: " . gettype($photo));

        // تبدیل photo به آرایه اگر Collection باشد
        $photoArray = null;
        if ($photo !== null) {
            if (is_array($photo)) {
                $photoArray = $photo;
                \Log::info("Photo is array with " . count($photo) . " elements");
            } elseif (is_object($photo)) {
                // ممکن است Collection باشد
                if (method_exists($photo, 'toArray')) {
                    $photoArray = $photo->toArray();
                    \Log::info("Photo is Collection/object, converted to array with " . count($photoArray) . " elements");
                } elseif (method_exists($photo, 'all')) {
                    $photoArray = $photo->all();
                    \Log::info("Photo is Collection, converted to array with " . count($photoArray) . " elements");
                } elseif ($photo instanceof \Illuminate\Support\Collection) {
                    $photoArray = $photo->toArray();
                    \Log::info("Photo is Illuminate Collection, converted to array with " . count($photoArray) . " elements");
                } else {
                    \Log::info("Photo is object but not Collection: " . get_class($photo));
                }
            }
        }

        // بررسی اینکه آیا پیام شامل تصویر است (photo یا document)
        // اول photo را چک کن (اولویت بالاتر - تصاویر فشرده شده)
        if ($photoArray && is_array($photoArray) && count($photoArray) > 0) {
            // تصویر به صورت photo ارسال شده (فشرده شده توسط تلگرام)
            \Log::info("Image sent as photo (compressed)");
            \Log::info("Photo array has " . count($photoArray) . " sizes");

            // دریافت بزرگترین سایز تصویر
            $photoSize = end($photoArray); // آخرین عنصر معمولاً بزرگترین سایز است

            // اگر end() کار نکرد، بزرگترین سایز را پیدا کن
            if (!$photoSize || !is_object($photoSize)) {
                $maxSize = 0;
                $maxPhotoSize = null;
                foreach ($photoArray as $size) {
                    if (is_object($size)) {
                        // بررسی با getFileSize
                        if (method_exists($size, 'getFileSize')) {
                            $currentSize = $size->getFileSize() ?? 0;
                            if ($currentSize > $maxSize) {
                                $maxSize = $currentSize;
                                $maxPhotoSize = $size;
                            }
                        } elseif (method_exists($size, 'getWidth') && method_exists($size, 'getHeight')) {
                            // اگر getFileSize نبود، از width * height استفاده کن
                            $currentSize = ($size->getWidth() ?? 0) * ($size->getHeight() ?? 0);
                            if ($currentSize > $maxSize) {
                                $maxSize = $currentSize;
                                $maxPhotoSize = $size;
                            }
                        }
                    }
                }
                $photoSize = $maxPhotoSize ?: (is_array($photoArray) && count($photoArray) > 0 ? $photoArray[count($photoArray) - 1] : null);
            }

            if ($photoSize) {
                try {
                    // اگر photoSize یک object است
                    if (is_object($photoSize)) {
                        if (method_exists($photoSize, 'getFileId')) {
                            $fileId = $photoSize->getFileId();
                        } elseif (method_exists($photoSize, 'get')) {
                            $fileId = $photoSize->get('file_id');
                        } elseif (isset($photoSize->file_id)) {
                            $fileId = $photoSize->file_id;
                        }
                    }
                    // اگر photoSize یک array است
                    elseif (is_array($photoSize)) {
                        $fileId = $photoSize['file_id'] ?? $photoSize['fileId'] ?? null;
                    }

                    if ($fileId) {
                        $fileExtension = 'jpg'; // تصاویر photo معمولاً jpg هستند
                        \Log::info("Photo file ID obtained: {$fileId}");
                    } else {
                        \Log::error("Could not get file ID from photo size. Type: " . gettype($photoSize));
                        if (is_object($photoSize)) {
                            \Log::error("Photo size object class: " . get_class($photoSize));
                            \Log::error("Photo size object methods: " . implode(', ', get_class_methods($photoSize)));
                        } elseif (is_array($photoSize)) {
                            \Log::error("Photo size array keys: " . implode(', ', array_keys($photoSize)));
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error("Error getting file ID from photo: " . $e->getMessage());
                    \Log::error("Stack trace: " . $e->getTraceAsString());
                }
            } else {
                \Log::error("Invalid photo size - is null or empty");
            }
        } elseif ($document && is_object($document)) {
            // تصویر به صورت document ارسال شده
            \Log::info("Image sent as document");

            // دریافت MIME type و نام فایل
            $mimeType = $document->getMimeType();
            $fileName = $document->getFileName();

            \Log::info("Document MIME type: " . ($mimeType ?? 'null'));
            \Log::info("Document file name: " . ($fileName ?? 'null'));

            // لیست فرمت‌های تصویر پشتیبانی شده
            $allowedImageMimeTypes = [
                'image/png',
                'image/jpeg',
                'image/jpg',
                'image/gif',
                'image/webp',
                'image/bmp',
                'image/x-ms-bmp',
                'image/tiff',
                'image/svg+xml',
            ];

            // بررسی extension از نام فایل (حتی اگر MIME type نباشد)
            $isImageByExtension = false;
            if ($fileName) {
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tiff', 'tif', 'svg'];
                if ($ext && in_array($ext, $allowedExtensions)) {
                    $isImageByExtension = true;
                    $fileExtension = $ext;
                    \Log::info("Image detected by extension: {$ext}");
                }
            }

            // بررسی MIME type
            $isImageByMimeType = false;
            if ($mimeType && in_array(strtolower($mimeType), $allowedImageMimeTypes)) {
                $isImageByMimeType = true;
                \Log::info("Image detected by MIME type: {$mimeType}");

                // اگر extension از نام فایل پیدا نشد، از MIME type استفاده کن
                if ($fileExtension === 'jpg') {
                    $mimeToExt = [
                        'image/png' => 'png',
                        'image/jpeg' => 'jpg',
                        'image/jpg' => 'jpg',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        'image/bmp' => 'bmp',
                        'image/x-ms-bmp' => 'bmp',
                        'image/tiff' => 'tiff',
                        'image/svg+xml' => 'svg',
                    ];
                    if (isset($mimeToExt[strtolower($mimeType)])) {
                        $fileExtension = $mimeToExt[strtolower($mimeType)];
                    }
                }
            }

            // اگر یا MIME type یا extension نشان دهد که تصویر است، قبول کن
            // یا اگر هیچکدام نبود، فایل را دانلود کن و بررسی کن
            if ($isImageByMimeType || $isImageByExtension) {
                $fileId = $document->getFileId();
                \Log::info("Document accepted as image. File ID: {$fileId}, Extension: {$fileExtension}");
            } else {
                // اگر MIME type و extension مشخص نبود، فایل را دانلود کن و بررسی کن
                \Log::info("Document MIME type and extension not clear. Downloading to verify...");
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
                            }
                            \Log::info("Document verified as image by getimagesize. MIME: {$detectedMime}, Extension: {$fileExtension}");
                            // فایل موقت را حذف می‌کنیم چون بعداً دوباره دانلود می‌شود
                            if (file_exists($tempDownloadedFile)) {
                                unlink($tempDownloadedFile);
                            }
                        } else {
                            // فایل تصویر نیست
                            if (file_exists($tempDownloadedFile)) {
                                unlink($tempDownloadedFile);
                            }
                            \Log::info("Document is not a valid image (verified by getimagesize)");
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "لطفا یک فایل تصویری (PNG، JPG، JPEG، GIF، WEBP و ...) ارسال کنید.",
                            ]);
                            return;
                        }
                    } else {
                        // اگر getimagesize موجود نبود، از document استفاده کن
                        $fileId = $tempFileId;
                        \Log::info("getimagesize not available, accepting document as-is");
                        if (file_exists($tempDownloadedFile)) {
                            unlink($tempDownloadedFile);
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error("Error verifying document: " . $e->getMessage());
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "خطایی در بررسی فایل رخ داد. لطفا دوباره تلاش کنید.",
                    ]);
                    return;
                }
            }
        }

        // اگر تصویر پیدا نشد
        if (!$fileId) {
            \Log::info("No valid image found in message");
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید. (فرمت‌های پشتیبانی شده: PNG، JPG، JPEG، GIF، WEBP، BMP)",
            ]);
            return;
        }

        try {
            \Log::info("File ID obtained: {$fileId}, Extension: {$fileExtension}");

            // دریافت اطلاعات فایل از Telegram
            $file = $this->telegram->getFile(['file_id' => $fileId]);
            $filePath = $file->getFilePath();

            // دانلود فایل از Telegram
            $tempPath = storage_path('app/temp');
            if (!file_exists($tempPath)) {
                mkdir($tempPath, 0755, true);
            }

            $downloadedFile = $this->telegram->downloadFile($file, $tempPath);

            // بررسی اینکه فایل دانلود شده واقعاً یک تصویر است (برای امنیت بیشتر)
            if (function_exists('getimagesize')) {
                $imageInfo = @getimagesize($downloadedFile);
                if ($imageInfo === false) {
                    \Log::error("Downloaded file is not a valid image");
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

                \Log::info("Verification image saved for member ID: {$member->id}, Format: {$fileExtension}");
            }

        } catch (\Exception $e) {
            \Log::error("Error handling verification image: " . $e->getMessage());
            \Log::error("Stack trace: " . $e->getTraceAsString());

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی در ارسال تصویر رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }
}

