<?php

namespace TornadoEngine\AccessMicrosoftEntra\Http;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule as ValidationRule;
use Illuminate\View\View;
use Throwable;
use TornadoEngine\Access\Models\Role;
use TornadoEngine\AccessMicrosoftEntra\AccessMicrosoftEntraServiceProvider;
use TornadoEngine\AccessMicrosoftEntra\Graph;
use TornadoEngine\AccessMicrosoftEntra\Models\Rule;
use TornadoEngine\Cron\Cron;

class EntraController extends Controller
{
    public function index(Cron $cron): View
    {
        $job = $cron->find(AccessMicrosoftEntraServiceProvider::JOB);

        return view('access-microsoft-entra::index', [
            'configured' => Graph::configured(),
            'usesSignInApp' => Graph::usesSignInApp(),
            'signInApp' => Setting::get('microsoft.client_id') !== null,
            'tenant' => Graph::configured() ? Graph::savedKeys()['tenant_id'] : null,
            'rules' => Rule::with('role')->get()->sortBy(fn (Rule $rule) => [$rule->role->name, $rule->type, $rule->label ?? $rule->value]),
            'roles' => Role::orderBy('name')->get(),
            'settings' => $cron->settings($job),
            'schedule' => $cron->scheduleFor($job),
        ]);
    }

    /**
     * Uses the Microsoft sign-in app registration, or saves its own, only if it can read users and groups.
     */
    public function connection(Request $request): RedirectResponse
    {
        if ($request->boolean('use_signin')) {
            abort_unless(Setting::get('microsoft.client_id') !== null, 422);
            $keys = ['tenant_id' => (string) Setting::get('microsoft.tenant_id'), 'client_id' => (string) Setting::get('microsoft.client_id'), 'client_secret' => (string) Setting::getSecret('microsoft.client_secret')];
        } else {
            $keys = $request->validate([
                'tenant_id' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i'],
                'client_id' => ['required', 'string', 'max:255'],
                'client_secret' => ['required', 'string', 'max:255'],
            ]);
        }

        if ($problem = (new Graph($keys))->check()) {
            return back()->withErrors(['connection' => $problem])->withInput($request->only('tenant_id', 'client_id', 'use_signin'));
        }

        Setting::put('entra.use_signin', $request->boolean('use_signin') ? '1' : '0');
        if (! $request->boolean('use_signin')) {
            Setting::put('entra.tenant_id', strtolower(trim($keys['tenant_id'])));
            Setting::put('entra.client_id', trim($keys['client_id']));
            Setting::putSecret('entra.client_secret', trim($keys['client_secret']));
        }

        return back()->with('status', __('Connected to Entra ID.'));
    }

    public function store(Request $request, Graph $graph): RedirectResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', ValidationRule::exists('access_roles', 'id')],
            'type' => ['required', ValidationRule::in(array_keys(Rule::TYPES))],
            'value' => ['required', 'string', 'max:255'],
        ], [], ['role_id' => __('role'), 'value' => __('value')]);

        // Check it exists now, so a typo doesn't make every nightly sync fail.
        try {
            [$value, $label] = $data['type'] === 'group'
                ? (($group = $graph->findGroup($data['value'])) ? [$group['id'], $group['name']] : [null, null])
                : (isset($graph->users()[strtolower(trim($data['value']))]) ? [strtolower(trim($data['value'])), null] : [null, null]);
        } catch (Throwable $e) {
            return back()->withErrors(['value' => __('Couldn\'t reach Entra ID: :reason', ['reason' => $e->getMessage()])])->withInput();
        }
        if ($value === null) {
            return back()->withErrors(['value' => __('Entra ID doesn\'t have that :thing.', ['thing' => strtolower(__(Rule::TYPES[$data['type']]))])])->withInput();
        }

        Rule::firstOrCreate(['role_id' => $data['role_id'], 'type' => $data['type'], 'value' => $value], ['label' => $label]);

        return back()->with('status', __('Added. People get the role at the next sync, or click Sync now.'));
    }

    public function destroy(Rule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('status', __('Removed. People who only had the role from this rule lose it at the next sync, or click Sync now.'));
    }

    public function sync(Cron $cron): RedirectResponse
    {
        $job = $cron->find(AccessMicrosoftEntraServiceProvider::JOB);
        $ok = $cron->run($job, wait: false);

        if ($ok === null) {
            return back()->withErrors(['sync' => __('Another scheduled job is running. Jobs run one at a time, so try again when it finishes.')]);
        }

        $message = $cron->settings($job)->last_message;

        return $ok
            ? back()->with('status', __('Synced. :summary', ['summary' => $message]))
            : back()->withErrors(['sync' => __('The sync failed, so nothing changed: :reason', ['reason' => $message])]);
    }
}
