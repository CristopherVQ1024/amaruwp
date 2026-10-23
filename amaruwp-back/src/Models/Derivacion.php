<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Derivacion extends Model
{
    protected $table = 'AGENTE_T_DERIVACIONES';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
