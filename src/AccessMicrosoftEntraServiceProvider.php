<?php

namespace TornadoEngine\AccessMicrosoftEntra;

use Illuminate\Support\ServiceProvider;
use TornadoEngine\Access\Access;
use TornadoEngine\Cron\Cron;
use TornadoEngine\Docs\Docs;

class AccessMicrosoftEntraServiceProvider extends ServiceProvider
{
    public const JOB = 'access-microsoft-entra.sync';

    public function boot(Access $access): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'access-microsoft-entra');
        $this->loadRoutesFrom(__DIR__.'/../routes/access-microsoft-entra.php');

        $access->tab('access.entra.index', __('Entra ID'), active: 'access.entra.*');

        $this->callAfterResolving(Cron::class, function (Cron $cron) {
            $cron->job(self::JOB, __('Sync roles from Entra ID'), fn () => app(Sync::class)->run(),
                schedule: '50 2 * * *',
                description: __('Gives roles to the people in your Entra ID rules (Users and roles → Entra ID), and takes them back from people who left.'));
        });

        $this->callAfterResolving(Docs::class, function (Docs $docs) {
            $docs->add('entra-id', __('Set up Entra ID'), 'access-microsoft-entra::docs.setup', order: 41);
        });
    }
}
