<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    protected $table = 'AGENDA_T_CITAS';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
