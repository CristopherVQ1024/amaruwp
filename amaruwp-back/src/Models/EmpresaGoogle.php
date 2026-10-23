<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaGoogle extends Model
{
    protected $table = 'T_EMPRESAS_GOOGLE';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
