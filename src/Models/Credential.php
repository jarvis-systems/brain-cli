<?php

declare(strict_types=1);

namespace BrainCLI\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Credential extends Model
{
    protected $table = 'credentials';

    protected $fillable = [
        'name',
        'value',
    ];
}

