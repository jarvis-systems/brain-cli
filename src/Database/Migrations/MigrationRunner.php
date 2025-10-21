<?php

declare(strict_types=1);

namespace BrainCLI\Database\Migrations;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

class MigrationRunner
{
    public static function run(): void
    {
        $schema = Capsule::schema();

//        if (!$schema->hasTable('servers')) {
//            $schema->create('servers', function (Blueprint $table) {
//                $table->increments('id');
//                $table->string('name')->unique();
//                $table->timestamps();
//            });
//        }

        if (! $schema->hasTable('credentials')) {
            $schema->create('credentials', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->text('value')->nullable();
                $table->timestamps();
            });

        }
    }
}

