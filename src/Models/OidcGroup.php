<?php

namespace Bausteln\SnipeitOidc\Models;

use App\Models\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A distinct OIDC group seen in login claims (or added manually). The admin
 * ticks `sync_enabled` to have it synced; `snipe_group_id` points at the
 * (auto-created) Snipe-IT group it syncs members into.
 */
class OidcGroup extends Model
{
    protected $table = 'oidc_groups';

    protected $fillable = [
        'name',
        'sync_enabled',
        'snipe_group_id',
        'last_seen_at',
    ];

    protected $casts = [
        'sync_enabled' => 'bool',
        'last_seen_at' => 'datetime',
    ];

    public function snipeGroup(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'snipe_group_id');
    }
}
