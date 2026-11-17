<?php

namespace TornadoEngine\AccessMicrosoftEntra\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TornadoEngine\Access\Models\Role;

/**
 * Gives a role to a group's members ("group": its object ID, with its name as label) or one
 * user ("user": their email). Entra ID has no OUs.
 */
class Rule extends Model
{
    public const TYPES = ['group' => 'Group', 'user' => 'User'];

    protected $table = 'access_microsoft_entra_rules';

    protected $fillable = ['role_id', 'type', 'value', 'label', 'primary', 'matched'];

    /** How the rule reads on the page, for example: the OU OU=Students,DC=district,DC=local. */
    public function describe(): string
    {
        return __('the :type :value', ['type' => strtolower(__(self::TYPES[$this->type])), 'value' => $this->label ?? $this->value]);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    protected function casts(): array
    {
        return ['primary' => 'boolean', 'matched' => 'integer'];
    }
}
