<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Hard stop before RefreshDatabase can wipe anything: tests only ever touch choosetounsi_test.
        $connection = $app['config']->get('database.default');
        $database   = $app['config']->get("database.connections.{$connection}.database");
        if ($database !== 'choosetounsi_test') {
            fwrite(STDERR, "Refusing to run tests against database \"{$database}\" — expected choosetounsi_test.\n");
            exit(1);
        }

        return $app;
    }
}
