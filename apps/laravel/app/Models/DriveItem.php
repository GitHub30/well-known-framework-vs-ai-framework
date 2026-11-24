<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DriveItem extends Model
{
    protected $table = 'items';
    public $timestamps = false;

    protected $fillable = [
        'parent_id', 'name', 'is_folder', 'mime_type', 'extension', 'size_bytes',
        'storage_name', 'owner_name', 'owner_email', 'description',
        'is_starred', 'is_shared', 'share_token', 'is_trashed', 'trashed_at',
        'last_opened_at', 'created_at', 'updated_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'parent_id' => 'integer',
        'is_folder' => 'integer',
        'size_bytes' => 'integer',
        'is_starred' => 'integer',
        'is_shared' => 'integer',
        'is_trashed' => 'integer',
    ];

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'item_id');
    }

    /** @return array<string, mixed> */
    public function toDriveArray(): array
    {
        return [
            'id' => (int)$this->id,
            'parent_id' => $this->parent_id !== null ? (int)$this->parent_id : null,
            'name' => (string)$this->name,
            'is_folder' => (int)$this->is_folder,
            'mime_type' => (string)$this->mime_type,
            'extension' => (string)$this->extension,
            'size_bytes' => (int)$this->size_bytes,
            'owner_name' => (string)$this->owner_name,
            'owner_email' => (string)$this->owner_email,
            'description' => (string)$this->description,
            'is_starred' => (int)$this->is_starred,
            'is_shared' => (int)$this->is_shared,
            'share_token' => $this->share_token !== null ? (string)$this->share_token : null,
            'is_trashed' => (int)$this->is_trashed,
            'trashed_at' => $this->trashed_at !== null ? (string)$this->trashed_at : null,
            'last_opened_at' => $this->last_opened_at !== null ? (string)$this->last_opened_at : null,
            'created_at' => (string)$this->created_at,
            'updated_at' => (string)$this->updated_at,
        ];
    }
}
