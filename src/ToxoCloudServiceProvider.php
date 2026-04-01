<?php

namespace Toxo\Cloud\Laravel;

use Illuminate\Support\ServiceProvider;

class ToxoCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/toxo-cloud.php',
            'toxo-cloud'
        );

        $this->app->singleton(ToxoCloudClient::class, function ($app) {
            $config = $app['config']->get('toxo-cloud');

            return new ToxoCloudClient(
                $config['api_key'] ?? null,
                $config['timeout'] ?? 120
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/toxo-cloud.php' => config_path('toxo-cloud.php'),
        ], 'config');
    }
}

