<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InitialPrelist extends Model
{
    protected $fillable = [
        'idsubsls',
        'kdkec',
        'nmkec',
        'kddes',
        'nmdesa',
        'kdsls',
        'kdsubsls',
        'nmsls',
        'nmsubsls',
        'total_assignment_fasih',
        'source_sheet',
        'source_file',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'total_assignment_fasih' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}
