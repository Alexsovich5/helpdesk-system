@extends('layouts.master')

@section('title', 'Tickets - Help Desk')

@section('content')
<div class="page-header">
	<a href="{{ URL::to('tickets/create') }}" class="btn btn-primary pull-right">New ticket</a>
	<h1>Tickets</h1>
</div>

{{ Form::open(array('url' => 'tickets', 'method' => 'get', 'class' => 'ticket-filters', 'role' => 'search')) }}
	<div class="row">
		<div class="col-xs-12 col-sm-6 col-md-3 form-group">
			{{ Form::label('q', 'Search', array('class' => 'control-label')) }}
			{{ Form::text('q', $filters['q'], array('class' => 'form-control', 'placeholder' => 'Number or subject')) }}
		</div>
		<div class="col-xs-6 col-sm-3 col-md-2 form-group">
			{{ Form::label('status', 'Status', array('class' => 'control-label')) }}
			{{ Form::select('status', array('' => 'Any') + array_combine($statuses, $statuses), $filters['status'], array('class' => 'form-control')) }}
		</div>
		<div class="col-xs-6 col-sm-3 col-md-2 form-group">
			{{ Form::label('priority', 'Priority', array('class' => 'control-label')) }}
			{{ Form::select('priority', array('' => 'Any') + array_combine($priorities, $priorities), $filters['priority'], array('class' => 'form-control')) }}
		</div>
		<div class="col-xs-6 col-sm-6 col-md-2 form-group">
			{{ Form::label('category', 'Category', array('class' => 'control-label')) }}
			{{ Form::select('category', array('' => 'Any') + $categories, $filters['category'], array('class' => 'form-control')) }}
		</div>
		@if ($user->isAgent())
		<div class="col-xs-6 col-sm-6 col-md-2 form-group">
			{{ Form::label('assignee', 'Assignee', array('class' => 'control-label')) }}
			{{ Form::select('assignee', array('' => 'Anyone', 'none' => 'Unassigned') + $agents, $filters['assignee'], array('class' => 'form-control')) }}
		</div>
		@endif
		<div class="col-xs-12 col-md-1 form-group">
			<label class="control-label hidden-xs hidden-sm">&nbsp;</label>
			<button type="submit" class="btn btn-default btn-block">Filter</button>
		</div>
	</div>
{{ Form::close() }}

@if ($tickets->count())
	@include('tickets._table', array('rows' => $tickets))
	<p class="text-muted">Showing {{ $tickets->getFrom() }}-{{ $tickets->getTo() }} of {{ $tickets->getTotal() }}.</p>
	{{ $tickets->links() }}
@else
	<p class="alert alert-info">No tickets match these filters.</p>
@endif
@stop
