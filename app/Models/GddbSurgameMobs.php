<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GddbSurgameMobs extends Model
{
    use HasFactory;

    protected $table = 'gddb_surgame_mobs';
    public $timestamps = false;
    protected $fillable = [
        'prefab',
        'hp',
        'atk',
        'def',
        'exp',
        'gold',
        'unique_id',
    ];

    protected $casts = [
        'prefab' => 'string',
        'hp' => 'integer',
        'atk' => 'integer',
        'def' => 'integer',
        'exp' => 'integer',
        'gold' => 'integer',
    ];
}
