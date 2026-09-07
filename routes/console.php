<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('zb-examine:provision-concurrency-testing-database', function () {
    $connection = config('database.default');
    $database = config("database.connections.{$connection}.database");

    // Refuse to run against anything but the isolated concurrency-test schema.
    if ($connection !== 'mysql' || $database !== 'zb_examine_test') {
        $this->error("Refusing to run: expected the 'mysql' connection pointed at database 'zb_examine_test', got connection '{$connection}' / database '{$database}'.");

        return 1;
    }

    $this->call('migrate:fresh', ['--force' => true]);

    $this->info("Provisioned and migrated '{$database}' on connection '{$connection}'.");

    return 0;
})->purpose('Migrate/reset the isolated zb_examine_test schema used by the submission-number concurrency test');
