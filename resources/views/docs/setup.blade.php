<x-setup.header :title="__('Set up Entra ID')" />

<div class="mt-6 px-6 sm:px-8 pb-6 sm:pb-8 space-y-6 text-sm text-gray-700 dark:text-gray-300">
    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('1. The app registration') }}</h2>
        <p>{{ __('If you set up Microsoft sign-in, you can use that app registration. Otherwise:') }}</p>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{!! __('Open :link, go to App registrations, and click New registration.', ['link' => '<a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener" class="font-medium text-indigo-600 dark:text-indigo-400 underline">Microsoft Entra admin center</a>']) !!}</li>
            <li>{{ __('Name it Tornado Engine, choose Accounts in this organizational directory only, and click Register.') }}</li>
            <li>{{ __('From Overview, copy the Application (client) ID and the Directory (tenant) ID.') }}</li>
            <li>{{ __('Under Certificates & secrets, click New client secret and copy its Value. Microsoft shows it only once. Note when it expires: make a new one before then.') }}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('2. Let it read users and groups') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{{ __('In the app registration, open API permissions and click Add a permission → Microsoft Graph → Application permissions.') }}</li>
            <li>{{ __('Add User.Read.All and GroupMember.Read.All.') }}</li>
            <li>{{ __('Click Grant admin consent.') }}</li>
        </ol>
    </section>

    <section class="space-y-2">
        <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('3. Connect and add rules') }}</h2>
        <ol class="list-decimal ps-5 space-y-1">
            <li>{{ __('In Users and roles → Entra ID, choose the Microsoft sign-in app or enter the IDs and secret, then Check and save.') }}</li>
            <li>{{ __('Add rules: groups by name, email or object ID (members of groups inside it count), or users by email.') }}</li>
        </ol>
    </section>
</div>
