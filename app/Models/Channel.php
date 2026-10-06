<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'type', 'waba_id', 'phone_number_id', 'display_phone', 'access_token', 'status',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
        ];
    }
}
