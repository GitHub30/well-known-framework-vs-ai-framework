<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    protected $table = 'activities';
    public $timestamps = false;

    protected $fillable = ['item_id', 'action', 'actor_name', 'detail', 'created_at'];

    protected $casts = [
        'id' => 'integer',
        'item_id' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(DriveItem::class, 'item_id');
    }
}
