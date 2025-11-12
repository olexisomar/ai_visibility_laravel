<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToAccount;

class Setting extends Model
{
    use BelongsToAccount;

    public $timestamps = false;
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'account_id',
        'key',
        'value',
    ];
    
    /**
     * Get setting value for current account
     */
    public static function getValue(string $key, $default = null)
    {
        $accountId = session('account_id');
        
        $setting = self::where('account_id', $accountId)
            ->where('key', $key)
            ->first();
        
        return $setting ? $setting->value : $default;
    }

    /**
     * Set setting value for current account
     */
    public static function setValue(string $key, $value): void
    {
        $accountId = session('account_id');
        
        self::updateOrCreate(
            ['account_id' => $accountId, 'key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Get primary brand ID for current account
     */
    public static function getPrimaryBrandId()
    {
        return self::getValue('primary_brand_id');
    }

    /**
     * Set primary brand ID for current account
     */
    public static function setPrimaryBrandId($brandId): void
    {
        self::setValue('primary_brand_id', $brandId);
    }

    public static function get(string $key, $default = null)
    {
        $setting = static::find($key);
        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}