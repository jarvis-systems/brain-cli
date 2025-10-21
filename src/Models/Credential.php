<?php

declare(strict_types=1);

namespace BrainCLI\Models;

use Illuminate\Database\Eloquent\Model;

class Credential extends Model
{
    protected $table = 'credentials';

    protected $fillable = [
        'name',
        'value',
    ];
}

