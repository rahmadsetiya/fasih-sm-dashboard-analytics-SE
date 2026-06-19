<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgressPengawas extends Model
{
    protected $connection = 'fasih';

    protected $table = 'progress_pengawas';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;
}
