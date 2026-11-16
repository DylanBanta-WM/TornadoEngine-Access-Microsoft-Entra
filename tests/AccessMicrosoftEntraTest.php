<?php

namespace TornadoEngine\AccessMicrosoftEntra\Tests;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TornadoEngine\Access\Models\Exclusion;
use TornadoEngine\Access\Models\Role;
use TornadoEngine\AccessMicrosoftEntra\Models\Rule;
use TornadoEngine\Cron\Cron;
use TornadoEngine\Cron\Models\CronJob;

class AccessMicrosoftEntraTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '11111111-2222-3333-4444-555555555555';

    public array $users = [
        ['id' => '1', 'displayName' => 'Ann Teacher', 'mail' => 'Ann@district.org', 'accountEnabled' => true],
        ['id' => '2', 'displayName' => 'Bob Office', 'mail' => null, 'userPrincipalName' => 'bob@district.org', 'accountEnabled' => true],
        ['id' => '3', 'displayName' => 'Gone', 'mail' => 'gone@district.org', 'accountEnabled' => false],
        ['id' => '4', 'displayName' => 'Guest', 'mail' => null, 'userPrincipalName' => 'guest_x.com#EXT#@district.onmicrosoft.com', 'accountEnabled' => true],
    ];

    public bool $graphDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Setting::put('entra.use_signin', '0');
        Setting::put('entra.tenant_id', 'district.onmicrosoft.com');
        Setting::put('entra.client_id', 'app');
        Setting::putSecret('entra.client_secret', 'secret');
        $this->fakeGraph();
    }

    public function fakeGraph(int $usersStatus = 200, int $groupsStatus = 200): void
    {
        Http::swap(new Factory);
        Http::fake(function (Request $request) use ($usersStatus, $groupsStatus) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, 'login.microsoftonline.com') => $request['client_secret'] === 'secret'
                    ? Http::response(['access_token' => 'token']) : Http::response(['error' => 'invalid_client'], 401),
                $this->graphDown => Http::response([], 503),
                str_contains($url, '/users?$skiptoken') => Http::response(['value' => array_slice($this->users, 2)]),
                str_contains($url, '/users') => $usersStatus === 200
                    ? Http::response(['value' => array_slice($this->users, 0, 2), '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/users?$skiptoken=abc'])
                    : Http::response([], $usersStatus),
                str_contains($url, '/transitiveMembers') => Http::response(['value' => [
                    ['@odata.type' => '#microsoft.graph.user', 'mail' => 'ann@district.org'],
                    ['@odata.type' => '#microsoft.graph.group', 'mail' => 'inner@district.org'],
                    ['@odata.type' => '#microsoft.graph.user', 'mail' => 'gone@district.org'],
                ]]),
                str_contains($url, "displayName eq 'Teachers'") => Http::response(['value' => [['id' => self::GROUP, 'displayName' => 'Teachers']]]),
                str_contains($url, '/groups?') || str_contains($url, '/groups') && ! str_contains($url, self::GROUP) => $groupsStatus === 200 ? Http::response(['value' => []]) : Http::response([], $groupsStatus),
                default => Http::response([], 404),
            };
        });
    }

    private function sync(): ?bool
    {
        return app(Cron::class)->run(app(Cron::class)->find('access-microsoft-entra.sync'));
    }

    private function rolesOf(string $email): array
    {
        return User::where('email', $email)->first()?->roles()->orderBy('name')->pluck('name')->all() ?? [];
    }

    private function manager(): User
    {
        return User::factory()->create(['is_admin' => true, 'email' => 'it@district.org']);
    }

    public function test_roles_come_from_groups_and_users_across_pages(): void
    {
        $teachers = Role::create(['name' => 'Teachers']);
        $office = Role::create(['name' => 'Office']);
        Rule::create(['role_id' => $teachers->id, 'type' => 'group', 'value' => self::GROUP, 'label' => 'Teachers']);
        Rule::create(['role_id' => $office->id, 'type' => 'user', 'value' => 'bob@district.org']);

        $this->assertTrue($this->sync(), (string) CronJob::find('access-microsoft-entra.sync')?->last_message);

        $this->assertSame(['Teachers'], $this->rolesOf('ann@district.org'));
        $this->assertSame(['Office'], $this->rolesOf('bob@district.org'));
        $this->assertNull(User::where('email', 'gone@district.org')->first(), 'disabled accounts get nothing');
        $this->assertSame(1, Rule::where('type', 'group')->value('matched'));
    }

    public function test_excluded_people_and_people_who_leave_lose_the_role(): void
    {
        $teachers = Role::create(['name' => 'Teachers']);
        Rule::create(['role_id' => $teachers->id, 'type' => 'group', 'value' => self::GROUP]);
        $this->sync();

        Exclusion::create(['email' => 'ann@district.org', 'reason' => 'Employment ended']);
        $this->sync();
        $this->assertSame([], $this->rolesOf('ann@district.org'));
    }

    public function test_nothing_changes_when_graph_cant_be_reached(): void
    {
        $teachers = Role::create(['name' => 'Teachers']);
        Rule::create(['role_id' => $teachers->id, 'type' => 'group', 'value' => self::GROUP]);
        $this->sync();

        $this->graphDown = true;
        $this->assertFalse($this->sync());
        $this->assertSame(['Teachers'], $this->rolesOf('ann@district.org'));
    }

    public function test_the_connection_is_checked_before_its_saved(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->from('/access/entra-id')->post('/access/entra-id/connection', ['tenant_id' => 'other', 'client_id' => 'app2', 'client_secret' => 'wrong'])
            ->assertSessionHasErrors(['connection' => 'Microsoft didn\'t accept the tenant ID, client ID and secret.']);

        $this->fakeGraph(groupsStatus: 403);
        $this->actingAs($manager)->from('/access/entra-id')->post('/access/entra-id/connection', ['tenant_id' => 'other', 'client_id' => 'app2', 'client_secret' => 'secret'])
            ->assertSessionHasErrors(['connection' => 'The app registration can\'t read groups. Add the GroupMember.Read.All application permission and grant admin consent.']);
        $this->assertSame('app', Setting::get('entra.client_id'));

        $this->fakeGraph();
        $this->actingAs($manager)->post('/access/entra-id/connection', ['tenant_id' => 'other', 'client_id' => 'app2', 'client_secret' => 'secret'])->assertSessionHas('status');
        $this->assertSame('app2', Setting::get('entra.client_id'));
    }

    public function test_it_can_use_the_microsoft_sign_in_app(): void
    {
        Setting::put('microsoft.tenant_id', 'district.onmicrosoft.com');
        Setting::put('microsoft.client_id', 'signin-app');
        Setting::putSecret('microsoft.client_secret', 'secret');

        $manager = $this->manager();
        $this->actingAs($manager)->post('/access/entra-id/connection', ['use_signin' => '1'])->assertSessionHas('status');
        $this->actingAs($manager)->get('/access/entra-id')->assertSee('the Microsoft sign-in app');
    }

    public function test_group_rules_are_found_by_name(): void
    {
        $role = Role::create(['name' => 'Teachers']);
        $manager = $this->manager();

        $this->actingAs($manager)->from('/access/entra-id')->post('/access/entra-id/rules', ['role_id' => $role->id, 'type' => 'group', 'value' => 'Nope'])->assertSessionHasErrors('value');
        $this->actingAs($manager)->post('/access/entra-id/rules', ['role_id' => $role->id, 'type' => 'group', 'value' => 'Teachers'])->assertSessionHasNoErrors();

        $this->assertSame([self::GROUP, 'Teachers'], [Rule::sole()->value, Rule::sole()->label]);
    }

    public function test_only_people_who_manage_access_can_open_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/access/entra-id')->assertForbidden();
        $this->actingAs($this->manager())->get('/access/users')->assertSee('Entra ID');
    }
}
