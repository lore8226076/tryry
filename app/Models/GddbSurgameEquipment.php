<?php
namespace App\Models;

use App\Models\GddbItems;
use App\Models\GddbSurgameEqQuility;
use Illuminate\Database\Eloquent\Model;

class GddbSurgameEquipment extends Model
{
    protected $table    = 'gddb_surgame_equipment';
    public $timestamps  = false;
    protected $fillable = [
        'item_id',
        'unique_id',
        'type',
        'name',
        'quility',
        'base_atk',
        'base_hp',
        'base_def',
        'slot_position',
    ];

    public function quality()
    {
        return $this->belongsTo(GddbSurgameEqQuility::class, 'quility', 'quility');
    }

    public function items()
    {
        return $this->belongsTo(GddbItems::class, 'unique_id', 'manager_id')->where('region', 'Surgame');
    }

    public function userEquipments()
    {
        return $this->hasMany(UserEquipmentSession::class, 'item_id', 'item_id');
    }
}
