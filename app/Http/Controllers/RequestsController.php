<?php

namespace App\Http\Controllers;

use App\Models\Member_Request;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Telegram\Bot\Api;

class RequestsController extends Controller
{
    /**
     * نمایش لیست درخواست‌های در انتظار (صفحه‌بندی ۲۰ تا)
     */
    public function index()
    {
        $requests = Member_Request::with('member')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('requests.index', compact('requests'));
    }

    /**
     * تایید درخواست (تغییر وضعیت به done + ارسال پیام تایید به کاربر از طریق ربات)
     */
    public function approve(Request $request, int $id)
    {
        $memberRequest = Member_Request::with('member')->where('status', 'pending')->findOrFail($id);
        $memberRequest->status = 'done';
        $memberRequest->save();

        $fromLabel = $memberRequest->from === 'lira' ? 'لیر' : ($memberRequest->from === 'rials' ? 'ریال' : $memberRequest->from);
        $toLabel = $memberRequest->to === 'lira' ? 'لیر' : ($memberRequest->to === 'rials' ? 'ریال' : $memberRequest->to);
        $amountFormatted = number_format((float) $memberRequest->amount);

        $member = $memberRequest->member;
        if ($member && $member->telegram_id) {
            try {
                $token = config('services.telegram.bot_token');
                if ($token) {
                    $telegram = new Api($token);
                    $text = "کاربر گرامی درخواست تبدیل {$fromLabel} به {$toLabel} شما به مبلغ {$amountFormatted} توسط مدیریت تایید گردید.\n\n";
                    $text .= "با تشکر از شما برای انتخاب لیر مارکت ❤️";
                    $telegram->sendMessage([
                        'chat_id' => $member->telegram_id,
                        'text' => $text,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('requests.index')->with('success', 'درخواست با موفقیت تایید شد.');
    }

    /**
     * رد درخواست (ذخیره دلیل رد + ارسال به کاربر از طریق ربات)
     */
    public function reject(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|max:2000',
        ], [
            'rejection_reason.required' => 'دلیل رد کردن درخواست را وارد کنید.',
        ]);

        if ($validator->fails()) {
            return redirect()->route('requests.index')
                ->withErrors($validator)
                ->withInput()
                ->with('reject_request_id', $id);
        }

        $memberRequest = Member_Request::with('member')->where('status', 'pending')->findOrFail($id);
        $memberRequest->status = 'rejected';
        $memberRequest->rejection_reason = $request->rejection_reason;
        $memberRequest->save();

        $member = $memberRequest->member;
        if ($member && $member->telegram_id) {
            try {
                $token = config('services.telegram.bot_token');
                if ($token) {
                    $telegram = new Api($token);
                    $text = "کاربر گرامی درخواست شما توسط مدیریت رد شد.\n\n";
                    $text .= "دلیل رد درخواست : " . $request->rejection_reason;
                    $telegram->sendMessage([
                        'chat_id' => $member->telegram_id,
                        'text' => $text,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('requests.index')->with('success', 'درخواست رد شد و دلیل برای کاربر ارسال گردید.');
    }

    /**
     * نمایش لیست درخواست‌های تایید شده (صفحه‌بندی ۲۰ تا)
     */
    public function approved()
    {
        $requests = Member_Request::with('member')
            ->where('status', 'done')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('requests.approved', compact('requests'));
    }
}


