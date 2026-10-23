<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'T_USERS';
    protected $primaryKey = 'ID';
    public $timestamps = false;
    protected $guarded = [];
}
