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

    protected $fillable = ['role_id', 'type', 'value', 'label', 'matched'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    protected function casts(): array
    {
        return ['matched' => 'integer'];
    }
}
