<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Member_Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class MembersController extends Controller
{
    protected $telegram;

    public function __construct()
    {
        $token = config('services.telegram.bot_token');
        if ($token) {
            $this->telegram = new Api($token);
        }
    }

    /**
     * نمایش لیست کاربران
     */
    public function index()
    {
        $members = Member::orderBy('created_at', 'desc')->paginate(30);
        return view('members.index', compact('members'));
    }

    /**
     * نمایش کاربران در انتظار تایید
     */
    public function pendingApproval()
    {
        $members = Member::where('is_verified', false)
            ->whereHas('documents')
            ->orderBy('created_at', 'desc')
            ->paginate(30);
        return view('members.pending-approval', compact('members'));
    }

    /**
     * دریافت اسناد verification کاربر
     */
    public function getDocuments($id)
    {
        $documents = Member_Document::where('member_id', $id)
            ->where('file_type', 'verification')
            ->get();

        return response()->json([
            'documents' => $documents->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'name' => $doc->name,
                    'file_url' => $doc->file_url,
                    'file_path' => $doc->file_path,
                ];
            })
        ]);
    }

    /**
     * تایید حساب کاربری
     */
    public function approve($id)
    {
        try {
            $member = Member::findOrFail($id);

            // تایید حساب کاربری
            $member->is_verified = true;
            $member->save();

            // ارسال پیام به کاربر از طریق تلگرام
            if ($this->telegram && $member->telegram_id) {
                $this->sendApprovalMessage($member->telegram_id);
            }

            return redirect()->route('members.pending-approval')
                ->with('success', 'حساب کاربری مورد نظر با موفقیت تایید شد.');
        } catch (\Exception $e) {
            return redirect()->route('members.pending-approval')
                ->with('error', 'خطا در تایید حساب کاربری: ' . $e->getMessage());
        }
    }

    /**
     * رد کردن حساب کاربری
     */
    public function reject($id, Request $request)
    {
        try {
            $member = Member::findOrFail($id);
            $reason="⚠️ حساب کاربری شما رد شد ⚠️\n\n";
            $reason.= "دلیل: " . $request->input('rejection_reason');


            // حذف تمام اسناد verification
            Member_Document::where('member_id', $id)
                ->where('file_type', 'verification')
                ->delete();

            // ارسال پیام به کاربر از طریق تلگرام
            if ($this->telegram && $member->telegram_id) {
                $this->sendRejectionMessage($member->telegram_id, $reason);
            }

            return redirect()->route('members.pending-approval')
                ->with('success', 'درخواست تایید حساب کاربری با موفقیت رد شد.');
        } catch (\Exception $e) {
            return redirect()->route('members.pending-approval')
                ->with('error', 'خطا در رد کردن حساب کاربری: ' . $e->getMessage());
        }
    }

    /**
     * ارسال پیام تایید به کاربر از طریق تلگرام
     */
    protected function sendApprovalMessage($chatId)
    {
        try {
            $message = " ✅ تبریک ، حساب کاربری شما توسط مدیریت لیر مارکت تایید شد . ";

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
        } catch (\Exception $e) {
            Log::error('Error sending telegram message: ' . $e->getMessage());
        }
    }

    /**
     * ارسال پیام رد به کاربر از طریق تلگرام
     */
    protected function sendRejectionMessage($chatId, $reason = null)
    {
        try {
            if ($reason && trim($reason) !== '') {
                // اگر دلیل وارد شده بود
                $message = trim($reason);
            } else {
                // اگر دلیل وارد نشده بود
                $message = "درخواست تایید هویت شما پذیرفته نشد،لطفا مجدد با دقت بیشتر تلاش کنید .";
            }

            // ارسال پیام رد
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
            ]);

            // برگشت به مرحله تایید حساب
            $this->sendVerificationMessage($chatId);
        } catch (\Exception $e) {
            Log::error('Error sending rejection telegram message: ' . $e->getMessage());
        }
    }

    /**
     * ارسال پیام تایید حساب برای کاربر
     */
    protected function sendVerificationMessage($chatId)
    {
        try {
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
        } catch (\Exception $e) {
            Log::error('Error sending verification telegram message: ' . $e->getMessage());
        }
    }
}


