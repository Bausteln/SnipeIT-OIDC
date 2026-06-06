@extends('layouts/default')

@section('title')
    OIDC Groups
@parent
@stop

@section('content')
<div class="row">
  <div class="col-md-12">

    @if (session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
      <div class="alert alert-danger">
        <ul style="margin:0; padding-left:1.2em;">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <div class="box box-default">
      <div class="box-header with-border"><h2 class="box-title">Add an OIDC group manually</h2></div>
      <div class="box-body">
        <p class="text-muted" style="margin-bottom:0.75rem;">
          Groups also appear here automatically after a user logs in with them.
        </p>
        <form method="POST" action="{{ route('oidc.admin.groups.store') }}" class="form-inline">
          @csrf
          <div class="form-group">
            <label for="name">OIDC group name</label>
            <input type="text" name="name" id="name" class="form-control"
                   value="{{ old('name') }}" placeholder="e.g. kc-it-admins" required>
          </div>
          <button type="submit" class="btn btn-primary">Add</button>
        </form>
      </div>
    </div>

    <div class="box box-default">
      <div class="box-header with-border"><h2 class="box-title">Discovered OIDC groups</h2></div>
      <div class="box-body table-responsive">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>OIDC group</th>
              <th>Last seen</th>
              <th>Snipe-IT group</th>
              <th>Sync</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
          @forelse ($groups as $group)
            <tr>
              <td>{{ $group->name }}</td>
              <td>{{ $group->last_seen_at ? $group->last_seen_at->diffForHumans() : '—' }}</td>
              <td>
                @if ($group->snipeGroup)
                  <a href="{{ url('/groups/'.$group->snipeGroup->id.'/edit') }}">
                    {{ $group->snipeGroup->name }} — set permissions
                  </a>
                @else
                  <span class="text-muted">not created yet</span>
                @endif
              </td>
              <td>
                <form method="POST" action="{{ route('oidc.admin.groups.toggle', $group) }}" style="margin:0;">
                  @csrf
                  @method('PATCH')
                  <label style="font-weight:normal; margin:0; cursor:pointer;">
                    <input type="checkbox" name="sync_enabled" value="1"
                           @checked($group->sync_enabled) onchange="this.form.submit()">
                    {{ $group->sync_enabled ? 'syncing' : 'off' }}
                  </label>
                </form>
              </td>
              <td class="text-right">
                <form method="POST" action="{{ route('oidc.admin.groups.destroy', $group) }}"
                      style="display:inline;" onsubmit="return confirm('Remove this group from the list?');">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                </form>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="5"><em>No OIDC groups discovered yet — they appear here after users
              log in, or add one manually above.</em></td>
            </tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
@stop
