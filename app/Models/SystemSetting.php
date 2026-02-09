<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $fillable = [
        'setting_name',
        'setting_value',
    ];

    /**
     * Get a setting value by name
     */
    public static function getValue(string $name, $default = null)
    {
        $setting = self::where('setting_name', $name)->first();
        return $setting ? $setting->setting_value : $default;
    }

    /**
     * Set a setting value by name
     */
    public static function setValue(string $name, $value): self
    {
        return self::updateOrCreate(
            ['setting_name' => $name],
            ['setting_value' => $value]
        );
    }
}
