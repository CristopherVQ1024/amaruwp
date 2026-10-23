<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Disponibilidad extends Model
{
    protected $table = 'AGENDA_T_DISPONIBILIDAD';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
