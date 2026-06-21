<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;

class ServeCommand extends BaseServeCommand
{
    protected function serverCommand(): array
    {
        $command = parent::serverCommand();

        array_splice($command, 1, 0, [
            '-d', 'upload_max_filesize=512M',
            '-d', 'post_max_size=513M',
        ]);

        return $command;
    }
}
