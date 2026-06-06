@extends('layouts/default')

@section('title')
    OIDC Group Mappings
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
      <div class="box-header with-border"><h2 class="box-title">Add mapping</h2></div>
      <div class="box-body">
        <form method="POST" action="{{ route('oidc.admin.groups.store') }}" class="form-inline">
          @csrf
          <div class="form-group">
            <label for="oidc_group">OIDC group</label>
            <input type="text" name="oidc_group" id="oidc_group" class="form-control"
                   value="{{ old('oidc_group') }}" placeholder="e.g. kc-it-admins" required>
          </div>
          <div class="form-group">
            <label for="snipe_group_id">Snipe-IT group</label>
            <select name="snipe_group_id" id="snipe_group_id" class="form-control" required>
              <option value="">— select —</option>
              @foreach ($groups as $group)
                <option value="{{ $group->id }}" @selected(old('snipe_group_id') == $group->id)>{{ $group->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="checkbox">
            <label><input type="checkbox" name="grants_superuser" value="1" @checked(old('grants_superuser'))> Grants superuser</label>
          </div>
          <div class="checkbox">
            <label><input type="checkbox" name="enabled" value="1" @checked(old('enabled', true))> Enabled</label>
          </div>
          <button type="submit" class="btn btn-primary">Add</button>
        </form>
      </div>
    </div>

    <div class="box box-default">
      <div class="box-header with-border"><h2 class="box-title">Mappings</h2></div>
      <div class="box-body table-responsive">
        <table class="table table-striped">
          <thead>
            <tr>
              <th>OIDC group</th><th>Snipe-IT group</th>
              <th>Superuser</th><th>Enabled</th><th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
          @forelse ($mappings as $mapping)
            <tr>
              <td>
                <form id="edit-{{ $mapping->id }}" method="POST" action="{{ route('oidc.admin.groups.update', $mapping) }}">
                  @csrf
                  @method('PUT')
                </form>
                <input form="edit-{{ $mapping->id }}" type="text" name="oidc_group"
                       class="form-control" value="{{ $mapping->oidc_group }}" required>
              </td>
              <td>
                <select form="edit-{{ $mapping->id }}" name="snipe_group_id" class="form-control" required>
                  @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected($mapping->snipe_group_id == $group->id)>{{ $group->name }}</option>
                  @endforeach
                </select>
              </td>
              <td><input form="edit-{{ $mapping->id }}" type="checkbox" name="grants_superuser" value="1" @checked($mapping->grants_superuser)></td>
              <td><input form="edit-{{ $mapping->id }}" type="checkbox" name="enabled" value="1" @checked($mapping->enabled)></td>
              <td class="text-right" style="white-space:nowrap;">
                <button form="edit-{{ $mapping->id }}" type="submit" class="btn btn-sm btn-default">Save</button>
                <form method="POST" action="{{ route('oidc.admin.groups.destroy', $mapping) }}"
                      style="display:inline;" onsubmit="return confirm('Remove this mapping?');">
                  @csrf
                  @method('DELETE')
                  <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="5"><em>No mappings yet — OIDC groups will not sync until you add one.</em></td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
@stop
