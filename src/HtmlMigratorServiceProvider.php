<?php

namespace Fddo\LaravelHtmlMigrator;

use Illuminate\Support\ServiceProvider;
use Fddo\LaravelHtmlMigrator\Commands\MigrateHtmlCommand;

class HtmlMigratorServiceProvider extends ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MigrateHtmlCommand::class,
            ]);
        }
    }

    public function register()
    {
        //
    }
}
