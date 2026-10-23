<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'CHATBOT_T_PRODUCTOS';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
