<?php

namespace TornadoEngine\AccessMicrosoftEntra;

use App\Models\Setting;
use TornadoEngine\Access\Imports;
use TornadoEngine\Access\PrimaryRules;
use TornadoEngine\AccessMicrosoftEntra\Models\Rule;

/**
 * Gives each rule's role to the enabled Entra ID users who match it, and takes it back from
 * people who don't anymore (through Access's Imports). Everything is read before anything
 * changes, so if Microsoft Graph can't be reached, nothing changes.
 */
class Sync
{
    public const SOURCE = 'microsoft-entra';

    public function __construct(private Graph $graph, private Imports $imports) {}

    public function run(): string
    {
        $rules = Rule::all();
        $people = $rules->isEmpty() ? [] : array_filter($this->graph->users(), fn ($user) => $user['enabled']);
        $excluded = $this->imports->excluded();

        $wanted = [];
        $matched = [];
        $emailsByRule = [];
        foreach ($rules as $rule) {
            $emails = $rule->type === 'group' ? $this->graph->groupMembers($rule->value) : [strtolower($rule->value)];
            $emails = array_filter($emails, fn ($email) => isset($people[$email]) && ! isset($excluded[$email]));

            $matched[$rule->id] = count($emails);
            $emailsByRule[$rule->id] = array_values($emails);
            foreach ($emails as $email) {
                $wanted[$rule->role_id][$email] = $people[$email]['name'];
            }
        }

        $result = $this->imports->apply(self::SOURCE, $wanted);
        foreach ($matched as $ruleId => $count) {
            Rule::whereKey($ruleId)->update(['matched' => $count]);
        }

        Setting::put('access_microsoft_entra.missed', json_encode(PrimaryRules::missed(
            $rules->load('role'), $emailsByRule, array_map(fn ($person) => $person['name'], $people), fn (Rule $rule) => $rule->describe(),
        )));

        return trans_choice(':count person matched.|:count people matched.', $result['people'])
            .' '.__('Gave :gave, took back :back.', [
                'gave' => trans_choice(':count role|:count roles', $result['gave']),
                'back' => trans_choice(':count role|:count roles', $result['took_back']),
            ])
            .($result['added'] ? ' '.trans_choice('Made :count new account.|Made :count new accounts.', $result['added']) : '');
    }
}
