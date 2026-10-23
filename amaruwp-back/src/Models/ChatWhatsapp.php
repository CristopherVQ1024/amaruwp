<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatWhatsapp extends Model
{
    protected $table = 'AGENTE_T_CHAT_WHATSAPP';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
