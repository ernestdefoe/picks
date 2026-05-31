<?php

namespace Resofire\Picks;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;
use Resofire\Picks\Service\CfbdService;
use Resofire\Picks\Service\LogoService;
use Resofire\Picks\Service\SyncScoresService;

class PicksServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Only the two services with a dependency the container can't auto-wire
        // need an explicit binding. Every other controller/service in this
        // extension has a fully type-hinted constructor whose parameters are
        // interfaces/classes already bound (SettingsRepositoryInterface, Queue,
        // CfbdService, etc.) or auto-instantiable (GuzzleHttp\Client), so Flarum
        // resolves them automatically — no closure required.

        // SyncScoresService: the concrete Guzzle client + queue are passed
        // explicitly so the wiring is unambiguous.
        $this->container->singleton(SyncScoresService::class, function ($container) {
            return new SyncScoresService(
                $container->make(CfbdService::class),
                $container->make(SettingsRepositoryInterface::class),
                $container->make(Queue::class),
                $container->make(HttpClient::class)
            );
        });

        // LogoService: the 'image' ImageManager is bound by string key, not by
        // class name, so auto-wiring `ImageManager $imageManager` would fail.
        $this->container->singleton(LogoService::class, function ($container) {
            return new LogoService(
                $container->make('image'),
                $container->make(FilesystemFactory::class),
                $container->make(HttpClient::class),
                $container->make(SettingsRepositoryInterface::class),
                $container->make(LoggerInterface::class)
            );
        });
    }
}
