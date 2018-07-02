<?php

namespace DigitalDrifter\LaravelCliSeeder;

use DigitalDrifter\Console\Commands\GenerateData;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * @var string
     */
    protected $packageName = 'cli-seeder';

    /**
     * @var array
     */
    protected $commands = [
        GenerateData::class,
    ];

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/config.php' => config_path($this->packageName . '.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands($this->commands);
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/config.php', $this->packageName
        );
    }
}
