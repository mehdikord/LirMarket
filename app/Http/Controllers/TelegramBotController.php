<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Member_Document;
use App\Models\Member_Request;
use App\Models\SystemSetting;
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

    /**
     * تبدیل اعداد فارسی و عربی به انگلیسی
     */
    protected function convertPersianToEnglish($string)
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $string = str_replace($persian, $english, $string);
        $string = str_replace($arabic, $english, $string);

        return $string;
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

                // چک کردن که message یک Collection نباشه
                if ($message && !($message instanceof \Illuminate\Support\Collection) && is_object($message) && method_exists($message, 'getChat')) {
                    $chat = $message->getChat();
                    if (!$chat || ($chat instanceof \Illuminate\Support\Collection) || !is_object($chat) || !method_exists($chat, 'getId')) {
                        continue;
                    }
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
                    } elseif ($userState === 'waiting_for_phone_number') {
                        // کاربر در حال وارد کردن شماره موبایل است
                        if ($message->has('text')) {
                            $text = $message->getText();
                            $this->handlePhoneNumberInput($chatId, $text);
                            $processed++;
                        }
                    } elseif ($userState === 'waiting_for_verify_code') {
                        // کاربر در حال وارد کردن کد فعالسازی است
                        if ($message->has('text')) {
                            $text = $message->getText();
                            $this->handleVerifyCodeInput($chatId, $text);
                            $processed++;
                        }
                    } elseif ($userState === 'waiting_for_lir_amount') {
                        // کاربر در حال وارد کردن مبلغ لیر است
                        if ($message->has('text')) {
                            $text = $message->getText();
                            $this->handleLirAmountInput($chatId, $text);
                            $processed++;
                        }
                    } elseif ($userState === 'waiting_for_receive_code') {
                        // کاربر در حال وارد کردن شماره شبا یا کارت است
                        if ($message->has('text')) {
                            $text = $message->getText();
                            $this->handleReceiveCodeInput($chatId, $text);
                            $processed++;
                        }
                    } elseif ($userState === 'waiting_for_receive_name') {
                        // کاربر در حال وارد کردن نام صاحب حساب است
                        if ($message->has('text')) {
                            $text = $message->getText();
                            $this->handleReceiveNameInput($chatId, $text);
                            $processed++;
                        }
                    } elseif ($userState === 'waiting_for_request_image') {
                        // کاربر در حال ارسال تصویر فاکتور/فیش است
                        if ($hasImage) {
                            $this->handleRequestImage($message, $chatId);
                            $processed++;
                        } elseif ($message->has('text')) {
                            $this->telegram->sendMessage([
                                'chat_id' => $chatId,
                                'text' => "لطفا تصویر فاکتور یا فیش خود را ارسال کنید.",
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
                        'text' => 'تبدیل 🇹🇷 لیر به 🇮🇷 ریال',
                        'callback_data' => 'lir_to_rial'
                    ]
                ],
                [
                    [
                        'text' => 'تبدیل 🇮🇷 ریال به 🇹🇷 لیر',
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

            // چک کردن که message یک Collection نباشه
            if ($message && !($message instanceof \Illuminate\Support\Collection)) {
                if (is_object($message) && method_exists($message, 'getChat')) {
                    $chat = $message->getChat();
                    if ($chat && !($chat instanceof \Illuminate\Support\Collection) && is_object($chat) && method_exists($chat, 'getId')) {
                        $chatId = $chat->getId();
                    }
                }
            }

            // اگر از message نشد، از from استفاده کن
            if (!$chatId) {
                $from = $callbackQuery->getFrom();
                if ($from && !($from instanceof \Illuminate\Support\Collection) && is_object($from) && method_exists($from, 'getId')) {
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
                \Log::info("User clicked lir_to_rial button (ID: {$chatId})");
                $this->handleLirToRialRequest($chatId);
                break;

            case 'rial_to_lir':
                // فعلا کاری انجام نمی‌دهیم
                \Log::info("User clicked rial_to_lir button (ID: {$chatId})");
                break;
        }
    }

    /**
     * شروع فرآیند تبدیل لیر به ریال
     */
    protected function handleLirToRialRequest($chatId)
    {
        try {
            $telegramId = (string) $chatId;
            $member = Member::where('telegram_id', $telegramId)->first();

            if (!$member) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "کاربری یافت نشد. لطفا دوباره /start بزنید.",
                ]);
                return;
            }

            // بررسی فعال بودن حساب
            if (!$member->is_verified) {
                $this->sendVerificationMessage($chatId);
                return;
            }

            // حساب فعال است - شروع فرآیند
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_lir_amount', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "لطفا مبلغ لیر مورد نظر خود را برای تبدیل به ریال وارد کنید (فقط به صورت عدد)",
            ]);

            \Log::info("Lir to Rial flow started for member ID: {$member->id}");

        } catch (\Exception $e) {
            \Log::error("Error in handleLirToRialRequest: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * پردازش مبلغ لیر وارد شده توسط کاربر
     */
    protected function handleLirAmountInput($chatId, $text)
    {
        try {
            // تبدیل اعداد فارسی/عربی به انگلیسی
            $amount = $this->convertPersianToEnglish(trim($text));

            // حذف کاراکترهای غیر عددی و نقطه (برای اعشار)
            // فقط عدد و نقطه مجاز است
            if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "مقدار وارد شده صحیح نمیباشد.\nلطفا مبلغ لیر مورد نظر خود را برای تبدیل به ریال وارد کنید (فقط به صورت عدد)",
                ]);
                return;
            }

            \Log::info("Lir amount entered: {$amount} for chat ID: {$chatId}");

            // ذخیره مبلغ در cache
            Cache::put("telegram_lir_amount_{$chatId}", $amount, 3600);

            // تغییر state به انتظار شماره شبا/کارت
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_receive_code', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "لطفا شماره شبا یا شماره کارت خود را برای واریز ریال وارد کنید.",
            ]);

        } catch (\Exception $e) {
            \Log::error("Error in handleLirAmountInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * پردازش شماره شبا یا کارت وارد شده توسط کاربر
     */
    protected function handleReceiveCodeInput($chatId, $text)
    {
        try {
            // تبدیل اعداد فارسی/عربی به انگلیسی
            $receiveCode = $this->convertPersianToEnglish(trim($text));

            \Log::info("Receive code entered: {$receiveCode} for chat ID: {$chatId}");

            // ذخیره شماره شبا/کارت در cache
            Cache::put("telegram_receive_code_{$chatId}", $receiveCode, 3600);

            // تغییر state به انتظار نام صاحب حساب
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_receive_name', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "نام صاحب حساب را وارد کنید.",
            ]);

        } catch (\Exception $e) {
            \Log::error("Error in handleReceiveCodeInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * پردازش نام صاحب حساب و ثبت درخواست
     */
    protected function handleReceiveNameInput($chatId, $text)
    {
        try {
            $receiveName = trim($text);

            \Log::info("Receive name entered: {$receiveName} for chat ID: {$chatId}");

            // دریافت مقادیر از cache
            $amount = Cache::get("telegram_lir_amount_{$chatId}");
            $receiveCode = Cache::get("telegram_receive_code_{$chatId}");

            if (!$amount || !$receiveCode) {
                \Log::error("Missing cached data for chat ID: {$chatId}");
                Cache::forget("telegram_user_state_{$chatId}");

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "خطایی رخ داد. لطفا دوباره از منو اصلی اقدام کنید.",
                ]);
                $this->showMainMenu($chatId);
                return;
            }

            // پیدا کردن member
            $telegramId = (string) $chatId;
            $member = Member::where('telegram_id', $telegramId)->first();

            if (!$member) {
                Cache::forget("telegram_user_state_{$chatId}");
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "کاربری یافت نشد. لطفا دوباره /start بزنید.",
                ]);
                return;
            }

            // ساخت کد تصادفی 8 رقمی
            $code = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

            // ثبت درخواست در جدول member_requests
            $request = Member_Request::create([
                'member_id' => $member->id,
                'from' => 'lira',
                'to' => 'rials',
                'amount' => $amount,
                'status' => 'pending',
                'recieve_name' => $receiveName,
                'receive_code' => $receiveCode,
                'code' => $code,
            ]);

            \Log::info("Member request created. ID: {$request->id}, Code: {$code}, Member: {$member->id}");

            // ذخیره request ID در cache برای ذخیره تصویر بعدی
            Cache::put("telegram_request_id_{$chatId}", $request->id, 3600);

            // پاک کردن cache های قبلی
            Cache::forget("telegram_lir_amount_{$chatId}");
            Cache::forget("telegram_receive_code_{$chatId}");

            // تغییر state به انتظار تصویر فاکتور
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_request_image', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "درخواست شما ثبت گردید.\nلطفا برای تکمیل درخواست تصویر فاکتور یا فیش خود را ارسال کنید.",
            ]);

        } catch (\Exception $e) {
            \Log::error("Error in handleReceiveNameInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * پردازش تصویر فاکتور/فیش درخواست
     */
    protected function handleRequestImage($message, $chatId)
    {
        $photo = $message->getPhoto();
        $document = $message->getDocument();
        $fileId = null;
        $fileExtension = 'jpg';

        // --- منطق پیدا کردن fileId از photo یا document (مشابه handleVerificationImage) ---
        $photoArray = null;
        if ($photo !== null) {
            if (is_array($photo)) {
                $photoArray = $photo;
            } elseif (is_object($photo)) {
                if (method_exists($photo, 'toArray')) {
                    $photoArray = $photo->toArray();
                } elseif (method_exists($photo, 'all')) {
                    $photoArray = $photo->all();
                }
            }
        }

        if ($photoArray && is_array($photoArray) && count($photoArray) > 0) {
            $photoSize = end($photoArray);
            if (!$photoSize || !is_object($photoSize)) {
                $maxSize = 0;
                $maxPhotoSize = null;
                foreach ($photoArray as $size) {
                    if (is_object($size) && method_exists($size, 'getFileSize')) {
                        $currentSize = $size->getFileSize() ?? 0;
                        if ($currentSize > $maxSize) {
                            $maxSize = $currentSize;
                            $maxPhotoSize = $size;
                        }
                    }
                }
                $photoSize = $maxPhotoSize ?: $photoArray[count($photoArray) - 1];
            }

            if ($photoSize && is_object($photoSize) && method_exists($photoSize, 'getFileId')) {
                $fileId = $photoSize->getFileId();
                $fileExtension = 'jpg';
            }
        } elseif ($document && is_object($document)) {
            $mimeType = $document->getMimeType();
            $fileName = $document->getFileName();

            $allowedImageMimeTypes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/bmp'];
            $isImage = false;

            if ($mimeType && in_array(strtolower($mimeType), $allowedImageMimeTypes)) {
                $isImage = true;
                $mimeToExt = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp', 'image/bmp' => 'bmp'];
                $fileExtension = $mimeToExt[strtolower($mimeType)] ?? 'jpg';
            }

            if ($fileName && !$isImage) {
                $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'])) {
                    $isImage = true;
                    $fileExtension = $ext;
                }
            }

            if ($isImage) {
                $fileId = $document->getFileId();
            }
        }

        if (!$fileId) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "لطفا تصویر فاکتور یا فیش خود را ارسال کنید. (فرمت‌های پشتیبانی شده: PNG، JPG، JPEG، GIF، WEBP، BMP)",
            ]);
            return;
        }

        try {
            // دانلود و ذخیره فایل
            $file = $this->telegram->getFile(['file_id' => $fileId]);
            $tempPath = storage_path('app/temp');
            if (!file_exists($tempPath)) {
                mkdir($tempPath, 0755, true);
            }

            $downloadedFile = $this->telegram->downloadFile($file, $tempPath);

            $storagePath = "members/requests";
            $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
            $fullPath = "{$storagePath}/{$fileName}";

            $fullStoragePath = storage_path("app/public/{$storagePath}");
            if (!file_exists($fullStoragePath)) {
                mkdir($fullStoragePath, 0755, true);
            }

            $fileContent = file_get_contents($downloadedFile);
            Storage::disk('public')->put($fullPath, $fileContent);

            if (file_exists($downloadedFile)) {
                unlink($downloadedFile);
            }

            // آپدیت درخواست با URL تصویر
            $requestId = Cache::get("telegram_request_id_{$chatId}");
            if ($requestId) {
                $memberRequest = Member_Request::find($requestId);
                if ($memberRequest) {
                    $fileUrl = url('storage/' . $fullPath);
                    $memberRequest->file_url = $fileUrl;
                    $memberRequest->save();
                    \Log::info("Request image saved for request ID: {$requestId}");
                }
            }

            // پاک کردن state و cache
            Cache::forget("telegram_user_state_{$chatId}");
            Cache::forget("telegram_request_id_{$chatId}");

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "تصویر فاکتور شما با موفقیت ثبت شد.\nدرخواست شما در حال بررسی میباشد. با تشکر از صبر و شکیبایی شما.",
            ]);

            // نمایش منو اصلی
            $this->showMainMenu($chatId);

        } catch (\Exception $e) {
            \Log::error("Error handling request image: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی در ارسال تصویر رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    protected function handleVerifyAccountRequest($chatId)
    {
        try {
            \Log::info("Starting handleVerifyAccountRequest for chat ID: {$chatId}");

            // چک کردن تنظیمات روش تایید
            $verifyMethod = SystemSetting::getValue('bot_verify', 'image');
            \Log::info("Verify method setting: {$verifyMethod}");

            if ($verifyMethod === 'code') {
                // روش تایید از طریق کد
                Cache::put("telegram_user_state_{$chatId}", 'waiting_for_phone_number', 3600); // 1 ساعت
                \Log::info("State set to waiting_for_phone_number");

                $message = "شماره موبایل خود را همراه با کد کشور وارد نمایید\nمثال : 989123334455";

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                ]);

                \Log::info("User requested verification (ID: {$chatId}) - waiting for phone number");
            } else {
                // روش تایید از طریق تصویر (پیش‌فرض)
                Cache::put("telegram_user_state_{$chatId}", 'waiting_for_verification_image', 3600); // 1 ساعت
                \Log::info("State set to waiting_for_verification_image");

                $message = "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید.";

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                ]);

                \Log::info("User requested verification (ID: {$chatId}) - waiting for image");
            }
        } catch (\Exception $e) {
            \Log::error("Error in handleVerifyAccountRequest: " . $e->getMessage());
            \Log::error("Error details: " . $e->getFile() . ":" . $e->getLine());
            \Log::error("Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * پردازش شماره موبایل وارد شده توسط کاربر
     */
    protected function handlePhoneNumberInput($chatId, $phone)
    {
        try {
            // تبدیل اعداد فارسی به انگلیسی
            $phone = $this->convertPersianToEnglish($phone);
            // حذف فاصله‌ها و کاراکترهای اضافی
            $phone = preg_replace('/[^0-9]/', '', $phone);
            \Log::info("Processing phone number: {$phone} for chat ID: {$chatId}");

            // جستجوی شماره موبایل در جدول members
            $member = Member::where('phone', $phone)->first();

            if (!$member) {
                \Log::info("No member found with phone: {$phone}");

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "کاربری با این شماره موبایل یافت نشد.\nلطفا با مدیریت تماس بگیرید.",
                ]);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "شماره موبایل خود را همراه با کد کشور وارد نمایید\nمثال : 989123334455",
                ]);
                return;
            }

            // بررسی اینکه آیا کاربر قبلا تایید شده
            if ($member->is_verified) {
                \Log::info("Member with phone {$phone} is already verified");

                // حذف state
                Cache::forget("telegram_user_state_{$chatId}");

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "حساب کاربری شما قبلا تایید شده است.",
                ]);

                // نمایش منو اصلی
                $this->showMainMenu($chatId);
                return;
            }

            // کاربر تایید نشده - درخواست کد فعالسازی
            \Log::info("Member found but not verified. Asking for verify code.");

            // ذخیره شماره موبایل در cache برای استفاده بعدی
            Cache::put("telegram_user_phone_{$chatId}", $phone, 3600);

            // تغییر state به انتظار کد فعالسازی
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_verify_code', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "کد فعالسازی ۶ رقمی خود را وارد کنید:",
            ]);

        } catch (\Exception $e) {
            \Log::error("Error in handlePhoneNumberInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * پردازش کد فعالسازی وارد شده توسط کاربر
     */
    protected function handleVerifyCodeInput($chatId, $code)
    {
        try {
            // تبدیل اعداد فارسی به انگلیسی
            $code = $this->convertPersianToEnglish($code);
            // حذف فاصله‌ها و کاراکترهای اضافی
            $code = preg_replace('/[^0-9]/', '', $code);
            \Log::info("Processing verify code: {$code} for chat ID: {$chatId}");

            // دریافت شماره موبایل از cache
            $phone = Cache::get("telegram_user_phone_{$chatId}");

            if (!$phone) {
                \Log::error("Phone number not found in cache for chat ID: {$chatId}");

                // حذف state و برگشت به ابتدا
                Cache::forget("telegram_user_state_{$chatId}");

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "خطایی رخ داد. لطفا دوباره روی دکمه تایید حساب کلیک کنید.",
                ]);

                $this->sendVerificationMessage($chatId);
                return;
            }

            // جستجوی کاربر با شماره موبایل و کد فعالسازی
            $member = Member::where('phone', $phone)
                           ->where('verify_code', $code)
                           ->first();

            if (!$member) {
                // کد اشتباه است
                \Log::info("Invalid verify code for phone: {$phone}");

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "کد فعالسازی شما اشتباه است.\nلطفا کد فعالسازی ۶ رقمی خود را مجددا وارد کنید:",
                ]);
                return;
            }

            // کد صحیح است - فعالسازی حساب
            \Log::info("Verify code correct. Activating member ID: {$member->id}");

            $telegramId = (string) $chatId;

            // پیدا کردن کاربری که با استارت ربات ساخته شده (با همین telegram_id)
            $duplicateMember = Member::where('telegram_id', $telegramId)
                                    ->where('id', '!=', $member->id)
                                    ->first();

            if ($duplicateMember) {
                \Log::info("Found duplicate member (ID: {$duplicateMember->id}) with same telegram_id. Merging...");

                // انتقال اطلاعاتی که ممکنه در duplicate باشه ولی در member اصلی نباشه
                if (empty($member->telegram_username) && !empty($duplicateMember->telegram_username)) {
                    $member->telegram_username = $duplicateMember->telegram_username;
                }

                // حذف کاربر duplicate
                $duplicateMember->delete();
                \Log::info("Duplicate member deleted.");
            }

            // آپدیت کاربر اصلی (که توسط مدیر ساخته شده)
            $member->is_verified = true;
            $member->telegram_id = $telegramId;
            $member->save();

            \Log::info("Member ID {$member->id} verified and telegram_id updated to {$telegramId}");

            // حذف state و cache
            Cache::forget("telegram_user_state_{$chatId}");
            Cache::forget("telegram_user_phone_{$chatId}");

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "حساب کاربری شما با موفقیت تایید شد.\nاکنون میتوانید از تمام سرویس های لیر مارکت استفاده نمایید.",
            ]);

            // نمایش منو اصلی
            $this->showMainMenu($chatId);

        } catch (\Exception $e) {
            \Log::error("Error in handleVerifyCodeInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
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

