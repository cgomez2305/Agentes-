<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class CalendarConnection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'provider', 'account_email', 'calendar_id', 'access_token', 'refresh_token', 'expires_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }
}
