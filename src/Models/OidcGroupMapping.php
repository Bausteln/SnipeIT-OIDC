<?php

namespace Bausteln\SnipeitOidc\Models;

use App\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One OIDC-group -> Snipe-IT-group mapping row. The table is the authoritative
 * source of truth for group sync (it also acts as the allowlist).
 */
class OidcGroupMapping extends Model
{
    protected $table = 'oidc_group_mappings';

    protected $fillable = [
        'oidc_group',
        'snipe_group_id',
        'grants_superuser',
        'enabled',
    ];

    protected $casts = [
        'grants_superuser' => 'bool',
        'enabled'          => 'bool',
    ];

    public function snipeGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'snipe_group_id');
    }
}
