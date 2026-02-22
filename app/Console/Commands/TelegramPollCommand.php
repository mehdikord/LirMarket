<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Member_Document;
use App\Models\Member_Request;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Morilog\Jalali\Jalalian;
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

        // اگه وب‌هوک فعال باشه، getUpdates هیچ آپدیتی نمیده؛ برای polling باید وب‌هوک خالی باشه
        try {
            $this->telegram->deleteWebhook();
            $this->info('Webhook cleared (polling mode).');
        } catch (\Exception $e) {
            $this->warn('Webhook check: ' . $e->getMessage());
        }

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
            $updateType = $update->objectType();

            $this->info("DEBUG: Update #{$updateId} type={$updateType}");

            // اول callback query رو چک کن (اولویت بالاتر)
            $callbackQuery = $update->getCallbackQuery();
            if ($callbackQuery) {
                $this->info("DEBUG: Callback query detected - Data: " . $callbackQuery->getData());
                $this->handleCallbackQuery($callbackQuery);
                if ($updateId > $newLastUpdateId) {
                    $newLastUpdateId = $updateId;
                }
                continue;
            }

            // فقط آپدیت‌های نوع message (پیام عادی) رو پردازش کن؛ از getRelatedObject استفاده کن تا شیء Message درست بگیریم
            if ($updateType !== 'message') {
                if ($updateId > $newLastUpdateId) {
                    $newLastUpdateId = $updateId;
                }
                continue;
            }

            try {
                $message = $update->getRelatedObject();
                $chat = $update->getChat();
            } catch (\Throwable $e) {
                $this->warn("DEBUG: Failed to get message/chat: " . $e->getMessage());
                if ($updateId > $newLastUpdateId) {
                    $newLastUpdateId = $updateId;
                }
                continue;
            }

            if (!$chat || !$chat->get('id')) {
                $this->warn("DEBUG: No chat in update");
                if ($updateId > $newLastUpdateId) {
                    $newLastUpdateId = $updateId;
                }
                continue;
            }

            $chatId = $chat->get('id');
            $username = $chat->get('username') ?? $chat->get('first_name') ?? '';

            // بررسی state کاربر
            $userState = Cache::get("telegram_user_state_{$chatId}");

            // بررسی اینکه آیا پیام شامل تصویر است
            $photoCheck = $message->getPhoto();
            $documentCheck = $message->getDocument();

            $hasPhoto = false;
            if ($photoCheck !== null) {
                if (is_array($photoCheck) && count($photoCheck) > 0) {
                    $hasPhoto = true;
                } elseif (is_object($photoCheck) && method_exists($photoCheck, 'toArray')) {
                    $photoArray = $photoCheck->toArray();
                    if (is_array($photoArray) && count($photoArray) > 0) {
                        $hasPhoto = true;
                    }
                }
            }
            $hasDocument = $documentCheck && is_object($documentCheck);
            $hasImage = $hasPhoto || $hasDocument;

            if ($userState === 'waiting_for_verification_image') {
                if ($hasImage) {
                    $this->handleVerificationImage($message, $chatId);
                } elseif ($message->get('text')) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید. (فرمت‌های پشتیبانی شده: PNG، JPG، JPEG، GIF، WEBP، BMP)",
                    ]);
                } else {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید.",
                    ]);
                }
            } elseif ($userState === 'waiting_for_phone_number') {
                $text = $message->get('text') ?? $message->getText() ?? '';
                if ((string) $text !== '') {
                    $this->handlePhoneNumberInput($chatId, (string) $text);
                }
            } elseif ($userState === 'waiting_for_verify_code') {
                $text = $message->get('text') ?? $message->getText() ?? '';
                if ((string) $text !== '') {
                    $this->handleVerifyCodeInput($chatId, (string) $text);
                }
            } elseif ($userState === 'waiting_for_amount') {
                $text = $message->get('text') ?? $message->getText() ?? '';
                if ((string) $text !== '') {
                    $this->handleAmountInput($chatId, (string) $text);
                }
            } elseif ($userState === 'waiting_for_receive_code') {
                $text = $message->get('text') ?? $message->getText() ?? '';
                if ((string) $text !== '') {
                    $this->handleReceiveCodeInput($chatId, (string) $text);
                }
            } elseif ($userState === 'waiting_for_receive_name') {
                $text = $message->get('text') ?? $message->getText() ?? '';
                if ((string) $text !== '') {
                    $this->handleReceiveNameInput($chatId, (string) $text);
                }
            } elseif ($userState === 'waiting_for_request_image') {
                if ($hasImage) {
                    $this->handleRequestImage($message, $chatId);
                } elseif ($message->get('text')) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "📸 لطفا تصویر فاکتور یا فیش خود را ارسال کنید.",
                    ]);
                }
            } else {
                // کاربر و سیستم در انتظار هیچ پیامی نیستند (مثلاً بعد از رد درخواست)
                $text = $message->get('text') ?? $message->getText() ?? '';
                if ((string) $text !== '') {
                    $this->info("Received message from {$username}: {$text}");

                    $command = trim(explode(' ', (string) $text)[0] ?? '');
                    if ($command === '/start' || str_starts_with((string) $command, '/start@')) {
                        $this->handleStartCommand($chat, $chatId);
                    } else {
                        // هر پیام دیگر: اگر کاربر تاییدشده است، منوی اصلی را نشان بده
                        $member = Member::where('telegram_id', (string) $chatId)->first();
                        if ($member && $member->is_verified) {
                            $this->showMainMenu($chatId);
                        }
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
        $firstName = $chat->get('first_name') ?? (method_exists($chat, 'getFirstName') ? $chat->getFirstName() : null);
        $lastName = $chat->get('last_name') ?? (method_exists($chat, 'getLastName') ? $chat->getLastName() : null);
        $username = $chat->get('username') ?? (method_exists($chat, 'getUsername') ? $chat->getUsername() : null);

        // چک کردن اینکه آیا کاربر قبلا وجود داشته
        $member = Member::where('telegram_id', $telegramId)->first();
        $isNewMember = false;

        if (!$member) {
            // کاربر جدید - دریافت و ذخیره اطلاعات
            $memberData = [
                'telegram_id' => $telegramId,
                'name' => trim(($firstName ?? '') . ' ' . ($lastName ?? '')),
                'telegram_username' => $username,
            ];

            // دریافت اطلاعات بیشتر از Telegram API
            try {
                $userProfile = $this->telegram->getChat(['chat_id' => $chatId]);

                if ($userProfile) {
                    $memberData['name'] = trim(
                        ($userProfile->get('first_name') ?? $userProfile->getFirstName() ?? '') . ' ' .
                        ($userProfile->get('last_name') ?? $userProfile->getLastName() ?? '')
                    );
                    $memberData['telegram_username'] = $userProfile->get('username') ?? $userProfile->getUsername();
                }
            } catch (\Exception $e) {
                $this->warn('Could not fetch additional user info: ' . $e->getMessage());
            }

            // ذخیره کاربر جدید
            $member = Member::create($memberData);
            $isNewMember = true;

            // ارسال پیام خوش‌آمدگویی
            $userName = trim($member->name) ?: ($firstName ?? 'کاربر');
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
            if ($message && is_object($message) && method_exists($message, 'getChat')) {
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
            $this->info("User clicked lir_to_rial button (ID: {$chatId})");
            $this->handleLirToRialRequest($chatId);
        } elseif ($dataTrimmed === 'rial_to_lir') {
            $this->info("User clicked rial_to_lir button (ID: {$chatId})");
            $this->handleRialToLirRequest($chatId);
        } elseif ($dataTrimmed === 'cancel_pending_request') {
            $this->info("User clicked cancel_pending_request button (ID: {$chatId})");
            $this->handleCancelPendingRequest($chatId);
        } else {
            $this->warn("No case matched for callback data: '{$dataTrimmed}'");
            $this->warn("Raw data: '" . $data . "'");
            $this->warn("Data hex: " . bin2hex($data));
        }
    }

    /**
     * بررسی وجود درخواست pending برای کاربر
     * اگر وجود داشت پیام و دکمه لغو نشان میدهد و true برمیگرداند
     */
    protected function checkPendingRequest($chatId, $member): bool
    {
        $pendingRequest = Member_Request::where('member_id', $member->id)
            ->where('status', 'pending')
            ->first();

        if (!$pendingRequest) {
            return false;
        }

        // تبدیل تاریخ به شمسی
        $shamsiDate = Jalalian::fromCarbon($pendingRequest->created_at)->format('Y/m/d H:i');

        // تعیین نوع درخواست
        $requestType = ($pendingRequest->from === 'lira') ? '🇹🇷 لیر به 🇮🇷 ریال' : '🇮🇷 ریال به 🇹🇷 لیر';

        $message = "⚠️ شما دارای یک درخواست تایید نشده هستید.\n";
        $message .= "ابتدا باید فرایند این درخواست تکمیل شود و یا میتوانید درخواست را لغو کنید.\n\n";
        $message .= "📋 اطلاعات درخواست:\n";
        $message .= "🔄 درخواست: {$requestType}\n";
        $message .= "💰 مبلغ: {$pendingRequest->amount}\n";
        $message .= "👤 نام صاحب حساب: " . ($pendingRequest->recieve_name ?? '---') . "\n";
        $message .= "📅 تاریخ ثبت: {$shamsiDate}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '❌ لغو درخواست',
                        'callback_data' => 'cancel_pending_request'
                    ]
                ]
            ]
        ];

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
        ]);

        return true;
    }

    /**
     * لغو درخواست pending کاربر
     */
    protected function handleCancelPendingRequest($chatId)
    {
        try {
            $telegramId = (string) $chatId;
            $member = Member::where('telegram_id', $telegramId)->first();

            if (!$member) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❌ کاربری یافت نشد. لطفا دوباره /start بزنید.",
                ]);
                return;
            }

            $pendingRequest = Member_Request::where('member_id', $member->id)
                ->where('status', 'pending')
                ->first();

            if ($pendingRequest) {
                $pendingRequest->status = 'cancel';
                $pendingRequest->save();
                $this->info("Request ID: {$pendingRequest->id} cancelled by user (chat ID: {$chatId})");

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "✅ درخواست شما با موفقیت لغو شد.",
                ]);
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "ℹ️ درخواست فعالی یافت نشد.",
                ]);
            }

            // پاک کردن state ها
            Cache::forget("telegram_user_state_{$chatId}");
            Cache::forget("telegram_flow_type_{$chatId}");
            Cache::forget("telegram_amount_{$chatId}");
            Cache::forget("telegram_receive_code_{$chatId}");
            Cache::forget("telegram_request_id_{$chatId}");

            // نمایش منو اصلی
            $this->showMainMenu($chatId);

        } catch (\Exception $e) {
            $this->error("Error in handleCancelPendingRequest: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
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
                    'text' => "❌ کاربری یافت نشد. لطفا دوباره /start بزنید.",
                ]);
                return;
            }

            // بررسی فعال بودن حساب
            if (!$member->is_verified) {
                $this->info("Member not verified, redirecting to verification (ID: {$chatId})");
                $this->sendVerificationMessage($chatId);
                return;
            }

            // بررسی وجود درخواست pending
            if ($this->checkPendingRequest($chatId, $member)) {
                return;
            }

            // ذخیره نوع فلو در cache
            Cache::put("telegram_flow_type_{$chatId}", 'lir_to_rial', 3600);

            // شروع فرآیند
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_amount', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "💰 لطفا مبلغ لیر مورد نظر خود را برای تبدیل به ریال وارد کنید\n🔢 (فقط به صورت عدد)",
            ]);

            $this->info("Lir to Rial flow started for member ID: {$member->id}");

        } catch (\Exception $e) {
            $this->error("Error in handleLirToRialRequest: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * شروع فرآیند تبدیل ریال به لیر
     */
    protected function handleRialToLirRequest($chatId)
    {
        try {
            $telegramId = (string) $chatId;
            $member = Member::where('telegram_id', $telegramId)->first();

            if (!$member) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❌ کاربری یافت نشد. لطفا دوباره /start بزنید.",
                ]);
                return;
            }

            // بررسی فعال بودن حساب
            if (!$member->is_verified) {
                $this->info("Member not verified, redirecting to verification (ID: {$chatId})");
                $this->sendVerificationMessage($chatId);
                return;
            }

            // بررسی وجود درخواست pending
            if ($this->checkPendingRequest($chatId, $member)) {
                return;
            }

            // ذخیره نوع فلو در cache
            Cache::put("telegram_flow_type_{$chatId}", 'rial_to_lir', 3600);

            // شروع فرآیند
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_amount', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "💰 لطفا مبلغ ریال مورد نظر خود را برای تبدیل به لیر وارد کنید\n🔢 (فقط به صورت عدد)",
            ]);

            $this->info("Rial to Lir flow started for member ID: {$member->id}");

        } catch (\Exception $e) {
            $this->error("Error in handleRialToLirRequest: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * پردازش مبلغ وارد شده توسط کاربر (عمومی برای هر دو فلو)
     */
    protected function handleAmountInput($chatId, $text)
    {
        try {
            $flowType = Cache::get("telegram_flow_type_{$chatId}", 'lir_to_rial');
            $isLirToRial = ($flowType === 'lir_to_rial');
            $currencyName = $isLirToRial ? 'لیر' : 'ریال';
            $targetCurrency = $isLirToRial ? 'ریال' : 'لیر';

            // تبدیل اعداد فارسی/عربی به انگلیسی
            $amount = $this->convertPersianToEnglish(trim($text));

            // فقط عدد و نقطه مجاز است
            if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "⚠️ مقدار وارد شده صحیح نمیباشد.\n💰 لطفا مبلغ {$currencyName} مورد نظر خود را برای تبدیل به {$targetCurrency} وارد کنید\n🔢 (فقط به صورت عدد)",
                ]);
                return;
            }

            $this->info("Amount entered: {$amount} for chat ID: {$chatId} (flow: {$flowType})");

            // ذخیره مبلغ در cache
            Cache::put("telegram_amount_{$chatId}", $amount, 3600);

            // تغییر state به انتظار شماره شبا/کارت
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_receive_code', 3600);

            $receiveMsg = $isLirToRial
                ? "💳 لطفا شماره شبا یا شماره کارت خود را برای واریز ریال وارد کنید."
                : "💳 لطفا شماره حساب یا شماره کارت خود را برای واریز لیر وارد کنید.";

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $receiveMsg,
            ]);

        } catch (\Exception $e) {
            $this->error("Error in handleAmountInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ خطایی رخ داد. لطفا دوباره تلاش کنید.",
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

            $this->info("Receive code entered: {$receiveCode} for chat ID: {$chatId}");

            // ذخیره شماره شبا/کارت در cache
            Cache::put("telegram_receive_code_{$chatId}", $receiveCode, 3600);

            // تغییر state به انتظار نام صاحب حساب
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_receive_name', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "👤 نام صاحب حساب را وارد کنید.",
            ]);

        } catch (\Exception $e) {
            $this->error("Error in handleReceiveCodeInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ خطایی رخ داد. لطفا دوباره تلاش کنید.",
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
            $flowType = Cache::get("telegram_flow_type_{$chatId}", 'lir_to_rial');
            $isLirToRial = ($flowType === 'lir_to_rial');

            $this->info("Receive name entered: {$receiveName} for chat ID: {$chatId} (flow: {$flowType})");

            // دریافت مقادیر از cache
            $amount = Cache::get("telegram_amount_{$chatId}");
            $receiveCode = Cache::get("telegram_receive_code_{$chatId}");

            if (!$amount || !$receiveCode) {
                $this->error("Missing cached data for chat ID: {$chatId}");
                Cache::forget("telegram_user_state_{$chatId}");
                Cache::forget("telegram_flow_type_{$chatId}");

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❌ خطایی رخ داد. لطفا دوباره از منو اصلی اقدام کنید.",
                ]);
                $this->showMainMenu($chatId);
                return;
            }

            // پیدا کردن member
            $telegramId = (string) $chatId;
            $member = Member::where('telegram_id', $telegramId)->first();

            if (!$member) {
                Cache::forget("telegram_user_state_{$chatId}");
                Cache::forget("telegram_flow_type_{$chatId}");
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❌ کاربری یافت نشد. لطفا دوباره /start بزنید.",
                ]);
                return;
            }

            // ساخت کد تصادفی 8 رقمی
            $code = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

            // تعیین from/to بر اساس فلو
            $from = $isLirToRial ? 'lira' : 'rials';
            $to = $isLirToRial ? 'rials' : 'lira';

            // ثبت درخواست در جدول member_requests
            $request = Member_Request::create([
                'member_id' => $member->id,
                'from' => $from,
                'to' => $to,
                'amount' => $amount,
                'status' => 'pending',
                'recieve_name' => $receiveName,
                'receive_code' => $receiveCode,
                'code' => $code,
            ]);

            $this->info("Member request created. ID: {$request->id}, Code: {$code}, Member: {$member->id}, Flow: {$flowType}");

            // ذخیره request ID در cache برای ذخیره تصویر بعدی
            Cache::put("telegram_request_id_{$chatId}", $request->id, 3600);

            // پاک کردن cache های قبلی
            Cache::forget("telegram_amount_{$chatId}");
            Cache::forget("telegram_receive_code_{$chatId}");

            // تغییر state به انتظار تصویر فاکتور
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_request_image', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ درخواست شما ثبت گردید.\n🧾 لطفا برای تکمیل درخواست تصویر فاکتور یا فیش خود را ارسال کنید. 📸",
            ]);

        } catch (\Exception $e) {
            $this->error("Error in handleReceiveNameInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ خطایی رخ داد. لطفا دوباره تلاش کنید.",
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

        // --- منطق پیدا کردن fileId از photo یا document ---
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
            // پیدا کردن بزرگترین سایز تصویر
            $maxSize = 0;
            $maxPhotoSize = null;
            foreach ($photoArray as $size) {
                $currentFileSize = 0;
                if (is_object($size)) {
                    if (method_exists($size, 'getFileSize')) {
                        $currentFileSize = $size->getFileSize() ?? 0;
                    } elseif (method_exists($size, 'getWidth') && method_exists($size, 'getHeight')) {
                        $currentFileSize = ($size->getWidth() ?? 0) * ($size->getHeight() ?? 0);
                    }
                } elseif (is_array($size)) {
                    $currentFileSize = $size['file_size'] ?? $size['fileSize'] ?? (($size['width'] ?? 0) * ($size['height'] ?? 0));
                }
                if ($currentFileSize > $maxSize) {
                    $maxSize = $currentFileSize;
                    $maxPhotoSize = $size;
                }
            }
            if (!$maxPhotoSize && count($photoArray) > 0) {
                $maxPhotoSize = end($photoArray);
            }

            if ($maxPhotoSize) {
                if (is_object($maxPhotoSize) && method_exists($maxPhotoSize, 'getFileId')) {
                    $fileId = $maxPhotoSize->getFileId();
                } elseif (is_object($maxPhotoSize) && method_exists($maxPhotoSize, 'get')) {
                    $fileId = $maxPhotoSize->get('file_id');
                } elseif (is_array($maxPhotoSize)) {
                    $fileId = $maxPhotoSize['file_id'] ?? null;
                }
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
                'text' => "📸 لطفا تصویر فاکتور یا فیش خود را ارسال کنید.\n🖼 (فرمت‌های پشتیبانی شده: PNG، JPG، JPEG، GIF، WEBP، BMP)",
            ]);
            return;
        }

        try {
            // دانلود فایل از تلگرام
            $file = $this->telegram->getFile(['file_id' => $fileId]);
            $tempPath = storage_path('app/temp');
            if (!file_exists($tempPath)) {
                mkdir($tempPath, 0755, true);
            }

            $downloadedFile = $this->telegram->downloadFile($file, $tempPath);

            // ذخیره فایل در مسیر images/requests با اسم رندوم
            $storagePath = "images/requests";
            $randomName = bin2hex(random_bytes(16)) . '.' . $fileExtension;
            $fullPath = "{$storagePath}/{$randomName}";

            $fullStoragePath = storage_path("app/public/{$storagePath}");
            if (!file_exists($fullStoragePath)) {
                mkdir($fullStoragePath, 0755, true);
            }

            $fileContent = file_get_contents($downloadedFile);
            Storage::disk('public')->put($fullPath, $fileContent);

            // حذف فایل موقت
            if (file_exists($downloadedFile)) {
                unlink($downloadedFile);
            }

            // ساخت URL مستقیم فایل
            $fileUrl = url('storage/' . $fullPath);
            $this->info("Request image saved to: {$fullPath}, URL: {$fileUrl}");

            // پیدا کردن member
            $telegramId = (string) $chatId;
            $member = Member::where('telegram_id', $telegramId)->first();

            if (!$member) {
                Cache::forget("telegram_user_state_{$chatId}");
                Cache::forget("telegram_request_id_{$chatId}");
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❌ کاربری یافت نشد. لطفا دوباره /start بزنید.",
                ]);
                return;
            }

            // پیدا کردن اولین درخواست pending این کاربر که فایل نداره
            $memberRequest = Member_Request::where('member_id', $member->id)
                ->where('status', 'pending')
                ->whereNull('file_url')
                ->first();

            if (!$memberRequest) {
                // اگر با whereNull پیدا نشد، با empty string هم چک کن
                $memberRequest = Member_Request::where('member_id', $member->id)
                    ->where('status', 'pending')
                    ->where('file_url', '')
                    ->first();
            }

            if ($memberRequest) {
                $memberRequest->file_url = $fileUrl;
                $memberRequest->save();
                $this->info("Request ID: {$memberRequest->id} updated with image URL");
            } else {
                $this->warn("No pending request without file found for member ID: {$member->id}");
            }

            // پاک کردن state و cache
            Cache::forget("telegram_user_state_{$chatId}");
            Cache::forget("telegram_request_id_{$chatId}");
            Cache::forget("telegram_flow_type_{$chatId}");

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "✅ درخواست شما با موفقیت ثبت گردید و در انتظار تایید مدیریت میباشد.\n\n🔔 بعد از تایید مدیریت پیام تایید برای شما ارسال میشود.\n\n🍀 موفق باشید.",
            ]);

            // نمایش منو اصلی
            $this->showMainMenu($chatId);

        } catch (\Exception $e) {
            $this->error("Error handling request image: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "❌ خطایی در ارسال تصویر رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    protected function handleVerifyAccountRequest($chatId)
    {
        try {
            $verifyMethod = SystemSetting::getValue('bot_verify', 'image');
            $this->info("Verify method from settings: {$verifyMethod}");

            if ($verifyMethod === 'code') {
                // روش تایید از طریق کد: اول شماره موبایل، بعد کد فعال‌سازی
                Cache::put("telegram_user_state_{$chatId}", 'waiting_for_phone_number', 3600);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "شماره موبایل خود را همراه با کد کشور وارد نمایید\nمثال : 989123334455",
                ]);
                $this->info("User (ID: {$chatId}) - waiting for phone number (code verification)");
            } else {
                // روش تایید از طریق تصویر (پیش‌فرض)
                Cache::put("telegram_user_state_{$chatId}", 'waiting_for_verification_image', 3600);
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "لطفا تصویر کارت ملی یا پاسپورت خود را ارسال کنید.",
                ]);
                $this->info("User (ID: {$chatId}) - waiting for verification image");
            }
        } catch (\Exception $e) {
            $this->error("Error in handleVerifyAccountRequest: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * تبدیل اعداد فارسی و عربی به انگلیسی
     */
    protected function convertPersianToEnglish($string): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $string = str_replace($persian, $english, $string);
        $string = str_replace($arabic, $english, $string);
        return $string;
    }

    /**
     * پردازش شماره موبایل وارد شده (برای تایید با کد)
     */
    protected function handlePhoneNumberInput($chatId, $phone): void
    {
        try {
            $phone = $this->convertPersianToEnglish($phone);
            $phone = preg_replace('/[^0-9]/', '', $phone);

            $member = Member::where('phone', $phone)->first();

            if (!$member) {
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

            if ($member->is_verified) {
                Cache::forget("telegram_user_state_{$chatId}");
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "حساب کاربری شما قبلا تایید شده است.",
                ]);
                $this->showMainMenu($chatId);
                return;
            }

            Cache::put("telegram_user_phone_{$chatId}", $phone, 3600);
            Cache::put("telegram_user_state_{$chatId}", 'waiting_for_verify_code', 3600);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "کد فعالسازی ۶ رقمی خود را وارد کنید:",
            ]);
        } catch (\Exception $e) {
            $this->error("Error in handlePhoneNumberInput: " . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "خطایی رخ داد. لطفا دوباره تلاش کنید.",
            ]);
        }
    }

    /**
     * پردازش کد فعالسازی وارد شده (برای تایید با کد)
     */
    protected function handleVerifyCodeInput($chatId, $code): void
    {
        try {
            $code = $this->convertPersianToEnglish($code);
            $code = preg_replace('/[^0-9]/', '', $code);

            $phone = Cache::get("telegram_user_phone_{$chatId}");
            if (!$phone) {
                Cache::forget("telegram_user_state_{$chatId}");
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "خطایی رخ داد. لطفا دوباره روی دکمه تایید حساب کلیک کنید.",
                ]);
                $this->sendVerificationMessage($chatId);
                return;
            }

            $member = Member::where('phone', $phone)->where('verify_code', $code)->first();

            if (!$member) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "کد فعالسازی شما اشتباه است.\nلطفا کد فعالسازی ۶ رقمی خود را مجددا وارد کنید:",
                ]);
                return;
            }

            $telegramId = (string) $chatId;

            $duplicateMember = Member::where('telegram_id', $telegramId)->where('id', '!=', $member->id)->first();
            if ($duplicateMember) {
                if (empty($member->telegram_username) && !empty($duplicateMember->telegram_username)) {
                    $member->telegram_username = $duplicateMember->telegram_username;
                }
                $duplicateMember->delete();
            }

            $member->is_verified = true;
            $member->telegram_id = $telegramId;
            $member->save();

            Cache::forget("telegram_user_state_{$chatId}");
            Cache::forget("telegram_user_phone_{$chatId}");

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "حساب کاربری شما با موفقیت تایید شد.\nاکنون میتوانید از تمام سرویس های لیر مارکت استفاده نمایید.",
            ]);
            $this->showMainMenu($chatId);
        } catch (\Exception $e) {
            $this->error("Error in handleVerifyCodeInput: " . $e->getMessage());
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
