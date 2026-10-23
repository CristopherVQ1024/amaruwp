<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empresa extends Model
{
    protected $table = 'T_EMPRESAS';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
