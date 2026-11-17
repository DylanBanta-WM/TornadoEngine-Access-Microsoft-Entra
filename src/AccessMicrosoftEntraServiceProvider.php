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

        // With the broker: names and employee IDs from Entra ID.
        $this->callAfterResolving('TornadoEngine\Broker\Broker', function ($broker) {
            $broker->source('entra-id', function (array $emails) {
                if (! Graph::configured()) {
                    return [];
                }
                $users = app(Graph::class)->users();

                return collect($emails)->filter(fn ($email) => isset($users[$email]))->mapWithKeys(fn ($email) => [$email => array_values(array_filter([
                    [$users[$email]['name'], 'name'], [$users[$email]['given'], 'given'], [$users[$email]['family'], 'family'], [$users[$email]['employee_id'], 'employeeId'],
                ], fn ($value) => $value[0] !== ''))])->all();
            });
        });

        $this->callAfterResolving(Docs::class, function (Docs $docs) {
            $docs->add('entra-id', __('Set up Entra ID'), 'access-microsoft-entra::docs.setup', order: 41);
        });
    }
}
