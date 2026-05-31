<?php

namespace Resofire\Picks;

use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\Queue;
use Resofire\Picks\Api\Controller\PublicStatsController;
use Resofire\Picks\Service\CurrentSeasonService;
use Resofire\Picks\Api\Controller\WeekOpenController;
use Resofire\Picks\Api\Controller\DeletePickController;
use Resofire\Picks\Api\Controller\EnterResultController;
use Resofire\Picks\Api\Controller\ListEventsController;
use Resofire\Picks\Api\Controller\ListLeaderboardController;
use Resofire\Picks\Api\Controller\ListPicksController;
use Resofire\Picks\Api\Controller\SubmitPickController;
use Resofire\Picks\Api\Controller\SyncScoresController;
use Resofire\Picks\Console\PollLiveScoresCommand;
use Resofire\Picks\Service\SyncScoresService;
use Resofire\Picks\Service\CfbdService;
use Resofire\Picks\Service\LogoService;
use Resofire\Picks\Service\ScheduleSyncService;
use Resofire\Picks\Service\TeamSyncService;

class PicksServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(SyncScoresService::class, function ($container) {
            return new SyncScoresService(
                $container->make(CfbdService::class),
                $container->make(SettingsRepositoryInterface::class),
                $container->make(Queue::class)
            );
        });

        $this->container->singleton(SyncScoresController::class, function ($container) {
            return new SyncScoresController(
                $container->make(SyncScoresService::class)
            );
        });

        $this->container->singleton(PollLiveScoresCommand::class, function ($container) {
            return new PollLiveScoresCommand(
                $container->make(SyncScoresService::class),
                $container->make(SettingsRepositoryInterface::class)
            );
        });

        $this->container->singleton(ListPicksController::class, function ($container) {
            return new ListPicksController(
                $container->make(SettingsRepositoryInterface::class)
            );
        });

        $this->container->singleton(ListLeaderboardController::class, function ($container) {
            return new ListLeaderboardController();
        });

        $this->container->singleton(SubmitPickController::class, function ($container) {
            return new SubmitPickController(
                $container->make(SettingsRepositoryInterface::class)
            );
        });

        $this->container->singleton(PublicStatsController::class, function ($container) {
            return new PublicStatsController(
                $container->make(SettingsRepositoryInterface::class),
                $container->make(CurrentSeasonService::class)
            );
        });

        $this->container->singleton(WeekOpenController::class, function ($container) {
            return new WeekOpenController();
        });

        $this->container->singleton(DeletePickController::class, function ($container) {
            return new DeletePickController();
        });

        $this->container->singleton(EnterResultController::class, function ($container) {
            return new EnterResultController(
                $container->make(Queue::class),
                $container->make(SyncScoresService::class)
            );
        });

        $this->container->singleton(ListEventsController::class, function ($container) {
            return new ListEventsController(
                $container->make(SettingsRepositoryInterface::class)
            );
        });

        $this->container->singleton(CfbdService::class, function ($container) {
            return new CfbdService(
                $container->make(SettingsRepositoryInterface::class),
                $container->make(HttpClient::class)
            );
        });

        $this->container->singleton(LogoService::class, function ($container) {
            return new LogoService(
                $container->make('image'),
                $container->make(FilesystemFactory::class),
                $container->make(HttpClient::class),
                $container->make(SettingsRepositoryInterface::class)
            );
        });

        $this->container->singleton(TeamSyncService::class, function ($container) {
            return new TeamSyncService(
                $container->make(CfbdService::class),
                $container->make(LogoService::class),
                $container->make(SettingsRepositoryInterface::class)
            );
        });

        $this->container->singleton(ScheduleSyncService::class, function ($container) {
            return new ScheduleSyncService(
                $container->make(CfbdService::class),
                $container->make(SettingsRepositoryInterface::class)
            );
        });
    }
}
