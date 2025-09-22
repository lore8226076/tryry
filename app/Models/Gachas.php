<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

use Carbon\Carbon;
class Gachas extends BaseModel
{
    // protected $connection = 'connection-name';
    protected $table = 'gachas';
    // protected $primaryKey = 'id';
    // use SoftDeletes;

    protected $hidden = [];

    protected $guarded = [
        'id', 'created_at', 'updated_at',
    ];
    // protected $fillable = [];
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    protected $appends = ['start_timestamp', 'end_timestamp'];

    protected $_virtual = ['start_timestamp', 'end_timestamp'];

    public function getStartTimestampAttribute()
    {
        return $this->start_time ? Carbon::parse($this->start_time)->timestamp : '';
    }

    public function getEndTimestampAttribute()
    {
        return $this->end_time ? Carbon::parse($this->end_time)->timestamp : '';
    }

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

    public function gachaDetails(){
        return $this->hasMany('App\Models\GachaDetails', 'gacha_id');
    }

}
