<?php

namespace App\Models;

use App\Enums\WebsiteStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Website extends Model
{
    use HasFactory;

    protected $fillable = ['client_id', 'url', 'status'];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'status' => WebsiteStatusEnum::class,
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
