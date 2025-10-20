<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GddbSurgamePlayerLvUp extends Model
{
    protected $table    = 'gddb_surgame_player_lv_up';
    public $timestamps  = false;
    protected $fillable = [
        'account_lv',
        'xp',
    ];
}
