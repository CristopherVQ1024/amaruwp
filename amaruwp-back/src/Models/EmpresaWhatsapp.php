<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpresaWhatsapp extends Model
{
    protected $table = 'T_EMPRESAS_WHATSAPP';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
