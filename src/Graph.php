<?php

namespace TornadoEngine\AccessMicrosoftEntra;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Entra ID through Microsoft Graph, with an app registration's own (application) permissions:
 * User.Read.All and GroupMember.Read.All.
 */
class Graph
{
    private const API = 'https://graph.microsoft.com/v1.0';

    /** @param  array{tenant_id: string, client_id: string, client_secret: string}|null  $keys  Keys being checked; null for the saved ones. */
    public function __construct(private ?array $keys = null) {}

    /** Whether it uses the Microsoft sign-in app registration (from Administration) instead of its own. */
    public static function usesSignInApp(): bool
    {
        return Setting::get('entra.use_signin') === '1' && Setting::get('microsoft.client_id') !== null;
    }

    public static function configured(): bool
    {
        return self::usesSignInApp() || Setting::get('entra.client_id') !== null;
    }

    /** @return array{tenant_id: string, client_id: string, client_secret: string} */
    public static function savedKeys(): array
    {
        $prefix = self::usesSignInApp() ? 'microsoft' : 'entra';

        return [
            'tenant_id' => (string) Setting::get("{$prefix}.tenant_id"),
            'client_id' => (string) Setting::get("{$prefix}.client_id"),
            'client_secret' => (string) Setting::getSecret("{$prefix}.client_secret"),
        ];
    }

    /**
     * Every user, by email (mail, or userPrincipalName without one).
     *
     * @return array<string, array{email: string, name: string, enabled: bool}>
     */
    public function users(): array
    {
        $users = [];
        foreach ($this->all('/users', ['$select' => 'id,displayName,mail,userPrincipalName,accountEnabled', '$top' => 999]) as $user) {
            if ($email = $this->email($user)) {
                $users[$email] = ['email' => $email, 'name' => (string) ($user['displayName'] ?? ''), 'enabled' => (bool) ($user['accountEnabled'] ?? true)];
            }
        }

        return $users;
    }

    /**
     * The emails of a group's members, including members of groups inside it.
     *
     * @return list<string>
     */
    public function groupMembers(string $groupId): array
    {
        $members = $this->all('/groups/'.rawurlencode($groupId).'/transitiveMembers', ['$select' => 'id,mail,userPrincipalName', '$top' => 999]);

        return array_values(array_filter(array_map(
            fn ($member) => ($member['@odata.type'] ?? '') === '#microsoft.graph.user' ? $this->email($member) : null,
            $members,
        )));
    }

    /**
     * A group, by its ID, email address or name.
     *
     * @return array{id: string, name: string}|null
     */
    public function findGroup(string $search): ?array
    {
        $search = trim($search);
        if (preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $search)) {
            $response = $this->request()->get(self::API.'/groups/'.$search, ['$select' => 'id,displayName']);

            return $response->successful() ? ['id' => $response->json('id'), 'name' => (string) $response->json('displayName')] : null;
        }

        $quoted = "'".str_replace("'", "''", $search)."'";
        $found = $this->request()->get(self::API.'/groups', ['$filter' => "displayName eq {$quoted} or mail eq {$quoted}", '$select' => 'id,displayName'])->throw()->json('value', []);

        return count($found) === 1 ? ['id' => $found[0]['id'], 'name' => (string) $found[0]['displayName']] : null;
    }

    /** Null if the app registration can read users and groups, or what's wrong. */
    public function check(): ?string
    {
        try {
            $users = $this->request()->get(self::API.'/users', ['$top' => 1, '$select' => 'id']);
            $groups = $this->request()->get(self::API.'/groups', ['$top' => 1, '$select' => 'id']);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return match (true) {
            $users->status() === 403 => __('The app registration can\'t read users. Add the User.Read.All application permission and grant admin consent.'),
            $groups->status() === 403 => __('The app registration can\'t read groups. Add the GroupMember.Read.All application permission and grant admin consent.'),
            ! $users->successful() || ! $groups->successful() => __('Microsoft Graph answered with an error (:status).', ['status' => $users->successful() ? $groups->status() : $users->status()]),
            default => null,
        };
    }

    /** Every page of a Graph list. */
    private function all(string $path, array $query): array
    {
        $items = [];
        $response = $this->request()->get(self::API.$path, $query)->throw();
        while (true) {
            array_push($items, ...$response->json('value', []));
            if (! ($next = $response->json('@odata.nextLink'))) {
                return $items;
            }
            $response = $this->request()->get($next)->throw();
        }
    }

    private function email(array $user): ?string
    {
        $email = strtolower((string) ($user['mail'] ?? $user['userPrincipalName'] ?? ''));

        return str_contains($email, '@') && ! str_contains($email, '#ext#') ? $email : null;
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->timeout(30);
    }

    private function token(): string
    {
        $keys = $this->keys ?? self::savedKeys();
        $get = function () use ($keys) {
            try {
                $response = Http::asForm()->timeout(15)->post('https://login.microsoftonline.com/'.rawurlencode($keys['tenant_id']).'/oauth2/v2.0/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $keys['client_id'],
                    'client_secret' => $keys['client_secret'],
                    'scope' => 'https://graph.microsoft.com/.default',
                ]);
            } catch (ConnectionException) {
                throw new RuntimeException(__('Couldn\'t reach Microsoft.'));
            }
            if (! $response->successful()) {
                throw new RuntimeException(__('Microsoft didn\'t accept the tenant ID, client ID and secret.'));
            }

            return (string) $response->json('access_token');
        };

        // Saved keys' tokens last about an hour; keep them a little less.
        return $this->keys ? $get() : Cache::remember('entra.token.'.md5($keys['tenant_id'].$keys['client_id']), 3000, $get);
    }
}
