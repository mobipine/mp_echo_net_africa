<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
    ];

    protected $casts = [
        'value' => 'string',
    ];

    public function getValueAttribute($value)
    {
        return match ($this->type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    public function setValueAttribute($value)
    {
        $this->attributes['value'] = match ($this->type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) $value,
            'json' => json_encode($value),
            default => (string) $value,
        };
    }

    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, $value, string $type = 'string', string $group = 'general', string $label = null, string $description = null)
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $type,
                'group' => $group,
                'label' => $label,
                'description' => $description,
            ]
        );
    }
}
