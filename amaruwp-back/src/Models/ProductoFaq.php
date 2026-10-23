<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoFaq extends Model
{
    protected $table = 'AGENTE_T_PRODUCTOS_FAQ';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
