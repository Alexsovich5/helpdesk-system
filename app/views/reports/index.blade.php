@extends('layouts.master')

@section('title', 'Reports - Help Desk')

@section('content')
<div class="page-header">
	<a href="{{{ URL::to('reports/export.csv').'?'.http_build_query($query) }}}" class="btn btn-default pull-right">Export CSV</a>
	<h1>Reports <small>{{ $summary['from']->format('j M Y') }} to {{ $summary['to']->format('j M Y') }}</small></h1>
</div>

{{ Form::open(array('url' => 'reports', 'method' => 'get', 'class' => 'report-range')) }}
	<div class="row">
		<div class="col-xs-6 col-sm-4 col-md-3 form-group">
			{{ Form::label('from', 'From', array('class' => 'control-label')) }}
			<input type="date" name="from" id="from" class="form-control" value="{{{ $query['from'] }}}">
		</div>
		<div class="col-xs-6 col-sm-4 col-md-3 form-group">
			{{ Form::label('to', 'To', array('class' => 'control-label')) }}
			<input type="date" name="to" id="to" class="form-control" value="{{{ $query['to'] }}}">
		</div>
		<div class="col-xs-12 col-sm-4 col-md-2 form-group report-range-submit">
			<button type="submit" class="btn btn-primary btn-block">Show</button>
		</div>
	</div>
{{ Form::close() }}

<div class="row">
	<div class="col-xs-6 col-md-3">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Created</h2></div>
			<div class="panel-body"><p class="report-figure">{{ $summary['created'] }}</p></div>
		</div>
	</div>
	<div class="col-xs-6 col-md-3">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Resolved</h2></div>
			<div class="panel-body"><p class="report-figure">{{ $summary['resolved'] }}</p></div>
		</div>
	</div>
	<div class="col-xs-6 col-md-3">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Mean first response</h2></div>
			<div class="panel-body">
				<p class="report-figure">{{ is_null($summary['mean_response_minutes']) ? '-' : number_format($summary['mean_response_minutes'], 1) }}</p>
				<p class="text-muted">minutes</p>
			</div>
		</div>
	</div>
	<div class="col-xs-6 col-md-3">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Mean resolution</h2></div>
			<div class="panel-body">
				<p class="report-figure">{{ is_null($summary['mean_resolution_minutes']) ? '-' : number_format($summary['mean_resolution_minutes'], 1) }}</p>
				<p class="text-muted">minutes</p>
			</div>
		</div>
	</div>
</div>

@if ($summary['created'] === 0)
<p class="alert alert-info">No tickets were created in this range.</p>
@else
<div class="row">
	<div class="col-xs-12 col-md-6">
		@include('reports._compliance', array('label' => 'Response', 'figures' => $summary['response']))
	</div>
	<div class="col-xs-12 col-md-6">
		@include('reports._compliance', array('label' => 'Resolution', 'figures' => $summary['resolution']))
	</div>
</div>

<div class="row">
	<div class="col-xs-12 col-md-4">
		@include('reports._breakdown', array('label' => 'status', 'counts' => $summary['by_status'], 'total' => $summary['created']))
	</div>
	<div class="col-xs-12 col-md-4">
		@include('reports._breakdown', array('label' => 'priority', 'counts' => $summary['by_priority'], 'total' => $summary['created']))
	</div>
	<div class="col-xs-12 col-md-4">
		@include('reports._breakdown', array('label' => 'category', 'counts' => $summary['by_category'], 'total' => $summary['created']))
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title">Agent workload</h2></div>
	@if (count($summary['agents']))
	<div class="table-responsive">
		<table class="table table-striped table-condensed">
			<thead>
				<tr>
					<th>Agent</th>
					<th class="text-right">Open</th>
					<th class="text-right">Resolved</th>
				</tr>
			</thead>
			<tbody>
				@foreach ($summary['agents'] as $agent)
				<tr>
					<td>{{{ $agent['name'] }}} <span class="text-muted hidden-xs">{{{ $agent['username'] }}}</span></td>
					<td class="text-right">{{ $agent['open'] }}</td>
					<td class="text-right">{{ $agent['resolved'] }}</td>
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
	@else
	<div class="panel-body"><p class="text-muted">None of these tickets has been assigned.</p></div>
	@endif
</div>
@endif

<p class="text-muted small">Figures cover tickets created in the range, except "Resolved", which counts tickets resolved in it. An SLA clock is measured once it has stopped or passed its due time.</p>
@stop
