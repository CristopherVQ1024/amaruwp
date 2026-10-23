<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaTelefono extends Model
{
    protected $table = 'T_EMPRESAS_TELEFONOS';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
