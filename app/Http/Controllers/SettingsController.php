<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    /**
     * تعریف تنظیمات سیستم و گزینه‌های آن‌ها
     */
    protected $settingsConfig = [
        'bot_verify' => [
            'label' => 'روش تایید هویت در ربات',
            'description' => 'انتخاب روش تایید هویت کاربران در ربات تلگرام',
            'options' => [
                'code' => 'از طریق کد',
                'image' => 'از طریق ارسال تصویر',
            ],
            'default' => 'image',
        ],
    ];

    /**
     * نمایش صفحه تنظیمات
     */
    public function index()
    {
        $settings = [];

        foreach ($this->settingsConfig as $name => $config) {
            $setting = SystemSetting::where('setting_name', $name)->first();
            $settings[$name] = [
                'name' => $name,
                'label' => $config['label'],
                'description' => $config['description'],
                'options' => $config['options'],
                'value' => $setting ? $setting->setting_value : $config['default'],
            ];
        }

        return view('settings.index', compact('settings'));
    }

    /**
     * بروزرسانی تنظیمات
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'setting_name' => 'required|string',
            'setting_value' => 'required|string',
        ]);

        $settingName = $validated['setting_name'];
        $settingValue = $validated['setting_value'];

        // بررسی معتبر بودن تنظیم
        if (!isset($this->settingsConfig[$settingName])) {
            return redirect()->route('settings.index')
                ->with('error', 'تنظیم مورد نظر یافت نشد');
        }

        // بررسی معتبر بودن مقدار
        $validOptions = array_keys($this->settingsConfig[$settingName]['options']);
        if (!in_array($settingValue, $validOptions)) {
            return redirect()->route('settings.index')
                ->with('error', 'مقدار انتخاب شده معتبر نیست');
        }

        // ذخیره تنظیم
        SystemSetting::setValue($settingName, $settingValue);

        return redirect()->route('settings.index')
            ->with('success', 'تنظیمات با موفقیت ذخیره شد');
    }
}
