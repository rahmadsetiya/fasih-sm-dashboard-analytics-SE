<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgressPencacah extends Model
{
    protected $connection = 'fasih';

    protected $table = 'progress_pencacah';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;
}
