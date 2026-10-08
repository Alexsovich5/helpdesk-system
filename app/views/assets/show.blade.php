@extends('layouts.master')

@section('title', $asset->asset_tag.' - Help Desk')

@section('content')
<div class="page-header">
	@if ($user->isAdmin())
	<div class="pull-right">
		<a href="{{ URL::to('assets/'.$asset->id.'/edit') }}" class="btn btn-default">Edit</a>
	</div>
	@endif
	<h1>{{{ $asset->name }}} <small>{{{ $asset->asset_tag }}}</small></h1>
</div>

@include('kb._errors')

<div class="row">
	<div class="col-xs-12 col-md-4">
		<div class="panel panel-default">
			<div class="panel-body">
				<dl class="asset-details">
					<dt>Type</dt><dd>{{{ $asset->type }}}</dd>
					<dt>Status</dt><dd>@include('assets._status_label', array('status' => $asset->status))</dd>
					<dt>Serial</dt><dd>{{{ $asset->serial ?: '-' }}}</dd>
					<dt>Location</dt><dd>{{{ $asset->location ?: '-' }}}</dd>
					<dt>Assigned to</dt><dd>{{{ $asset->assignedUser ? $asset->assignedUser->name : 'Unassigned' }}}</dd>
					<dt>Updated</dt><dd>{{{ $asset->updated_at->format('Y-m-d H:i') }}}</dd>
				</dl>
			</div>
		</div>

		@if ($user->isAdmin() && ! count($asset->tickets))
		{{ Form::open(array('url' => 'assets/'.$asset->id, 'method' => 'delete', 'role' => 'form')) }}
			<button type="submit" class="btn btn-link text-danger">Delete asset</button>
		{{ Form::close() }}
		@endif
	</div>

	<div class="col-xs-12 col-md-8">
		<h2 class="h4">Ticket history</h2>
		@if (count($asset->tickets))
		@include('tickets._table', array('rows' => $asset->tickets))
		@else
		<p class="text-muted">No tickets refer to this asset.</p>
		@endif
	</div>
</div>
@stop
