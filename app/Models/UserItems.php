<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

use Carbon\Carbon;
class UserItems extends BaseModel
{
    // protected $connection = 'connection-name';
    protected $table = 'user_items';
    // protected $primaryKey = 'id';
    // use SoftDeletes;

    public const REGION_AVATAR = 'Avatar';
    public const REGION_MAP = 'Map';

    protected $hidden = [];

    protected $guarded = [
        'id', 'created_at', 'updated_at',
    ];
    // protected $fillable = [];
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'publish_at' => 'datetime:Y-m-d H:i:s',
    ];

    protected $appends = [];

    protected $_virtual = [];

    protected static function boot()
    {
        parent::boot();
        // creating, created, saving, saved, deleting, deleted
        static::creating(function ($entity) {
        });
        static::saving(function ($entity) {
        });
        static::saved(function ($entity) {
        });
        static::deleting(function ($entity) {
        });
    }

    public function user()
    {
        return $this->belongsTo('App\Models\Users', 'user_id');
    }

    public function item()
    {
        return $this->belongsTo('App\Models\GddbItems', 'item_id', 'item_id');
    }
}
