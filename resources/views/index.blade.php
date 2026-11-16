@php
    $select = 'mt-1 block w-full min-h-11 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-base focus:border-indigo-500 focus:ring-indigo-500';
    $placeholders = ['group' => 'Teachers', 'user' => 'name@district.org'];
@endphp

<x-admin-layout :title="__('Users and roles')">
    @include('access::tabs')

    @if ($errors->has('sync'))
        <p class="rounded-md bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-800 dark:text-red-300">{{ $errors->first('sync') }}</p>
    @endif

    <x-settings-card :title="__('Connection')">
        @if (Route::has('docs.show'))
            <p><a href="{{ route('docs.show', 'entra-id') }}" class="font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('How to set up Entra ID') }}</a></p>
        @endif

        <div x-data="{ editing: @js(! $configured || $errors->has('connection')), own: @js(! (old('use_signin', $usesSignInApp || ! $configured) && $signInApp)) }" class="space-y-4">
            @if ($configured)
                <div x-show="! editing" class="space-y-4">
                    <dl class="space-y-1 break-all">
                        <div><dt class="inline font-medium text-gray-900 dark:text-gray-100">{{ __('Tenant:') }}</dt> <dd class="inline">{{ $tenant }}</dd></div>
                        <div><dt class="inline font-medium text-gray-900 dark:text-gray-100">{{ __('App registration:') }}</dt> <dd class="inline">{{ $usesSignInApp ? __('the Microsoft sign-in app') : __('saved') }}</dd></div>
                    </dl>
                    <div class="flex justify-end">
                        <x-secondary-button type="button" x-on:click="editing = true">{{ __('Change') }}</x-secondary-button>
                    </div>
                </div>
            @endif

            <form x-show="editing" @if ($configured) x-cloak @endif method="POST" action="{{ route('access.entra.connection') }}" class="space-y-4">
                @csrf
                @if ($signInApp)
                    <label class="flex min-h-11 items-center gap-3">
                        <input type="hidden" name="use_signin" value="0">
                        <input type="checkbox" name="use_signin" value="1" x-on:change="own = ! $event.target.checked" class="rounded text-indigo-600 border-gray-300 dark:border-gray-700 dark:bg-gray-900 focus:ring-indigo-500" x-bind:checked="! own">
                        <span>{{ __('Use the Microsoft sign-in app registration') }}</span>
                    </label>
                @endif
                <div x-show="own" class="space-y-4">
                    @foreach (['tenant_id' => __('Directory (tenant) ID'), 'client_id' => __('Application (client) ID')] as $field => $label)
                        <div>
                            <x-input-label :for="'entra_'.$field" :value="$label" />
                            <x-text-input :id="'entra_'.$field" class="mt-1 block w-full" type="text" :name="$field" :value="old($field)" x-bind:required="own" autocomplete="off" />
                        </div>
                    @endforeach
                    <div>
                        <x-input-label for="entra_client_secret" :value="__('Client secret value')" />
                        <x-text-input id="entra_client_secret" class="mt-1 block w-full" type="password" name="client_secret" x-bind:required="own" autocomplete="off" />
                    </div>
                </div>
                <x-input-error :messages="$errors->get('connection')" />
                <div class="flex justify-end gap-3">
                    @if ($configured)
                        <x-secondary-button type="button" x-on:click="editing = false">{{ __('Cancel') }}</x-secondary-button>
                    @endif
                    <x-primary-button>{{ __('Check and save') }}</x-primary-button>
                </div>
            </form>
        </div>
    </x-settings-card>

    @if ($configured)
        <x-settings-card :title="__('Roles from Entra ID')">
            @if ($rules->isNotEmpty())
                <ul class="divide-y divide-gray-200 dark:divide-gray-700 rounded-md border border-gray-200 dark:border-gray-700">
                    @foreach ($rules as $rule)
                        <li class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                            <div class="min-w-0">
                                <p class="font-medium text-gray-900 dark:text-gray-100">{{ $rule->role->name }}</p>
                                <p class="break-all text-gray-600 dark:text-gray-400">{{ __(\TornadoEngine\AccessMicrosoftEntra\Models\Rule::TYPES[$rule->type]) }}: <code class="font-mono">{{ $rule->label ?? $rule->value }}</code></p>
                                @if ($rule->matched !== null)
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ trans_choice(':count person at the last sync|:count people at the last sync', $rule->matched) }}</p>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('access.entra.rules.destroy', $rule) }}" class="-ms-2 sm:ms-0">
                                @csrf
                                @method('DELETE')
                                <button class="min-h-11 px-2 text-sm text-red-700 dark:text-red-400 underline">{{ __('Remove') }}</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($roles->isEmpty())
                <p>{!! __('Make a role on the :roles tab first.', ['roles' => '<a href="'.route('access.roles.index').'" class="font-medium text-indigo-600 dark:text-indigo-400 underline">'.e(__('Roles')).'</a>']) !!}</p>
            @else
                <form method="POST" action="{{ route('access.entra.rules.store') }}" class="space-y-3" x-data="{ type: @js(old('type', 'group')) }">
                    @csrf
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <x-input-label for="role_id" :value="__('Role')" />
                            <select id="role_id" name="role_id" class="{{ $select }}" required>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}" @selected((int) old('role_id') === $role->id)>{{ $role->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="type" :value="__('Given to')" />
                            <select id="type" name="type" x-model="type" class="{{ $select }}">
                                @foreach (\TornadoEngine\AccessMicrosoftEntra\Models\Rule::TYPES as $type => $label)
                                    <option value="{{ $type }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <x-input-label for="value" x-text="{ group: @js(__('Group name, email or ID')), user: @js(__('Email address')) }[type]">{{ __('Value') }}</x-input-label>
                            <x-text-input id="value" name="value" class="mt-1 block w-full" type="text" :value="old('value')" required autocapitalize="off" spellcheck="false" x-bind:placeholder="{{ Js::from($placeholders) }}[type]" />
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('value')" />
                    <div class="flex justify-end">
                        <x-primary-button>{{ __('Add rule') }}</x-primary-button>
                    </div>
                </form>
            @endif
        </x-settings-card>

        <x-settings-card :title="__('Sync')">
            <p>
                @if ($settings->last_run_at)
                    {{ __('Last sync :time:', ['time' => $settings->last_run_at->diffForHumans()]) }}
                    <span @class(['font-medium', 'text-green-700 dark:text-green-400' => $settings->last_status === 'ok', 'text-red-700 dark:text-red-400' => $settings->last_status !== 'ok'])>{{ $settings->last_status === 'ok' ? __('worked') : __('failed') }}</span>
                    @if ($settings->last_message) — {{ $settings->last_message }}@endif
                @else
                    {{ __('Hasn\'t synced yet.') }}
                @endif
            </p>
            <p class="text-gray-600 dark:text-gray-400">
                {{ $settings->enabled ? __('Runs on its own: :when', ['when' => \TornadoEngine\Cron\Explain::describe($schedule)]) : __('The nightly sync is turned off.') }}
                @can('administration')
                    <a href="{{ route('administration.tab', 'cron') }}" class="font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('Change on Scheduled jobs') }}</a>
                @endcan
            </p>
            <form method="POST" action="{{ route('access.entra.sync') }}" class="flex justify-end">
                @csrf
                <x-primary-button>{{ __('Sync now') }}</x-primary-button>
            </form>
        </x-settings-card>
    @endif
</x-admin-layout>
