<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PetugasName extends Model
{
    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['username', 'display_name'];
}
