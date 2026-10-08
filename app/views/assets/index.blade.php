@extends('layouts.master')

@section('title', 'Assets - Help Desk')

@section('content')
<div class="page-header">
	@if ($user->isAdmin())
	<div class="pull-right">
		<a href="{{ URL::to('assets/create') }}" class="btn btn-primary">New asset</a>
	</div>
	@endif
	<h1>Assets</h1>
</div>

{{ Form::open(array('url' => 'assets', 'method' => 'get', 'class' => 'asset-filters', 'role' => 'search')) }}
	<div class="row">
		<div class="col-xs-12 col-sm-7 form-group">
			{{ Form::label('q', 'Search assets', array('class' => 'sr-only')) }}
			{{ Form::text('q', $filters['q'], array('class' => 'form-control', 'placeholder' => 'Tag, name, serial or location')) }}
		</div>
		<div class="col-xs-8 col-sm-3 form-group">
			{{ Form::label('status', 'Status', array('class' => 'sr-only')) }}
			{{ Form::select('status', array('' => 'Any status') + array_combine($statuses, str_replace('_', ' ', $statuses)), $filters['status'], array('class' => 'form-control')) }}
		</div>
		<div class="col-xs-4 col-sm-2 form-group">
			<button type="submit" class="btn btn-default btn-block">Filter</button>
		</div>
	</div>
{{ Form::close() }}

@if ($assets->count())
<div class="table-responsive">
	<table class="table table-striped table-condensed">
		<thead>
			<tr>
				<th>Tag</th>
				<th>Name</th>
				<th class="hidden-xs">Type</th>
				<th>Status</th>
				<th class="hidden-xs">Assigned to</th>
				<th class="hidden-xs">Location</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($assets as $asset)
			<tr>
				<td><a href="{{ URL::to('assets/'.$asset->id) }}">{{{ $asset->asset_tag }}}</a></td>
				<td>{{{ $asset->name }}}</td>
				<td class="hidden-xs">{{{ $asset->type }}}</td>
				<td>@include('assets._status_label', array('status' => $asset->status))</td>
				<td class="hidden-xs">{{{ $asset->assignedUser ? $asset->assignedUser->name : '-' }}}</td>
				<td class="hidden-xs">{{{ $asset->location ?: '-' }}}</td>
			</tr>
			@endforeach
		</tbody>
	</table>
</div>
<p class="text-muted">Showing {{ $assets->getFrom() }}-{{ $assets->getTo() }} of {{ $assets->getTotal() }}.</p>
{{ $assets->links() }}
@elseif ($filters['q'] !== '' || $filters['status'] !== '')
<p class="alert alert-info">No assets match these filters.</p>
@else
<p class="alert alert-info">The register is empty. Admins can add assets here or load a CSV export with <code>php artisan assets:import</code>.</p>
@endif
@stop
