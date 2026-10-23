<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductoFoto extends Model
{
    protected $table = 'CHATBOT_T_PRODUCTOS_FOTOS';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
