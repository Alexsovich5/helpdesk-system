@extends('layouts.master')

@section('title', $ticket->number.' - Help Desk')

@section('content')
<div class="page-header">
	<h1>{{{ $ticket->subject }}} <small>{{{ $ticket->number }}}</small></h1>
</div>

@if ($errors->any())
<div class="alert alert-danger">
	<ul class="list-unstyled">
		@foreach ($errors->all() as $message)
		<li>{{{ $message }}}</li>
		@endforeach
	</ul>
</div>
@endif

<div class="row">
	<div class="{{ $user->isAgent() ? 'col-xs-12 col-md-8' : 'col-xs-12' }}">
		<div class="panel panel-default">
			<div class="panel-body">
				<dl class="dl-horizontal ticket-details">
					<dt>Status</dt><dd>@include('tickets._status_label', array('status' => $ticket->status))</dd>
					<dt>Priority</dt><dd>{{{ $ticket->priority }}}</dd>
					<dt>Category</dt><dd>{{{ $ticket->category ? $ticket->category->name : '' }}}</dd>
					<dt>Requester</dt><dd>{{{ $ticket->requester->name }}}</dd>
					<dt>Assignee</dt><dd>{{{ $ticket->assignee ? $ticket->assignee->name : 'Unassigned' }}}</dd>
					@if ($ticket->asset)
					<dt>Asset</dt><dd>@if ($user->isAgent())<a href="{{ URL::to('assets/'.$ticket->asset->id) }}">{{{ $ticket->asset->label() }}}</a>@else{{{ $ticket->asset->label() }}}@endif</dd>
					@endif
					<dt>Created</dt><dd>{{{ $ticket->created_at->format('Y-m-d H:i') }}}</dd>
					<dt>SLA</dt><dd>@include('tickets._sla_badge', array('state' => $ticket->sla_state))</dd>
					<dt>Response due</dt><dd>{{{ $ticket->response_due_at ? $ticket->response_due_at->format('Y-m-d H:i') : '-' }}}@if ($ticket->first_responded_at) <span class="text-muted small">(answered {{{ $ticket->first_responded_at->format('Y-m-d H:i') }}})</span>@endif</dd>
					<dt>Resolution due</dt><dd>{{{ $ticket->resolution_due_at ? $ticket->resolution_due_at->format('Y-m-d H:i') : '-' }}}@if ($ticket->resolved_at) <span class="text-muted small">(resolved {{{ $ticket->resolved_at->format('Y-m-d H:i') }}})</span>@endif</dd>
				</dl>
				<div class="ticket-description">{{ nl2br(e($ticket->description)) }}</div>
			</div>
		</div>

		@if ( ! $user->isAgent())
		@include('tickets._articles')
		@endif

		@if ( ! $user->isAgent() && $ticket->status === 'resolved' && $ticket->isOwnedBy($user))
		<div class="well well-sm">
			<p>This ticket has been marked resolved.</p>
			{{ Form::open(array('url' => 'tickets/'.$ticket->number.'/status', 'class' => 'form-inline-buttons')) }}
				<button type="submit" name="status" value="closed" class="btn btn-success">Confirm and close</button>
				<button type="submit" name="status" value="open" class="btn btn-default">Reopen</button>
			{{ Form::close() }}
		</div>
		@endif

		<h2 class="h4">History</h2>
		<ul class="list-unstyled ticket-timeline">
			@foreach ($timeline as $entry)
				@if ($entry['kind'] === 'comment')
				<li class="panel {{ $entry['item']->is_internal ? 'panel-warning' : 'panel-default' }}">
					<div class="panel-heading">
						<strong>{{{ $entry['item']->author->name }}}</strong>
						@if ($entry['item']->is_internal) <span class="label label-warning">internal</span> @endif
						<span class="text-muted small pull-right">{{{ $entry['at']->format('Y-m-d H:i') }}}</span>
					</div>
					<div class="panel-body">{{ nl2br(e($entry['item']->body)) }}</div>
				</li>
				@else
				<?php $event = $entry['item']; ?>
				<li class="text-muted small ticket-event">
					{{{ $entry['at']->format('Y-m-d H:i') }}}
					&middot; {{{ $event->user ? $event->user->name : 'System' }}}
					@if ($event->type === 'created')
						opened the ticket
					@elseif ($event->type === 'assigned')
						assigned it to {{{ $event->to_value }}}
					@elseif ($event->type === 'status')
						changed status from {{{ $event->from_value }}} to {{{ $event->to_value }}}
					@elseif ($event->type === 'asset_linked')
						asset linked: {{{ $event->to_value }}}@if ($event->from_value) (was {{{ $event->from_value }}})@endif
					@elseif ($event->type === 'article_linked')
						article linked: {{{ $event->to_value }}}
					@else
						{{{ str_replace('_', ' ', $event->type) }}}@if ($event->to_value): {{{ $event->from_value ? $event->from_value.' to ' : '' }}}{{{ $event->to_value }}}@endif
					@endif
				</li>
				@endif
			@endforeach
		</ul>

		@if ($ticket->status !== 'closed')
		<h2 class="h4">Add a comment</h2>
		{{ Form::open(array('url' => 'tickets/'.$ticket->number.'/comments', 'role' => 'form')) }}
			<div class="form-group {{ $errors->has('body') ? 'has-error' : '' }}">
				{{ Form::label('body', 'Comment', array('class' => 'sr-only')) }}
				{{ Form::textarea('body', null, array('class' => 'form-control', 'rows' => 4)) }}
			</div>
			@if ($user->isAgent())
			<div class="checkbox">
				<label>{{ Form::checkbox('is_internal', 1) }} Internal note (hidden from the requester)</label>
			</div>
			@endif
			<button type="submit" class="btn btn-primary btn-block-xs">Add comment</button>
		{{ Form::close() }}
		@endif
	</div>

	@if ($user->isAgent())
	<div class="col-xs-12 col-md-4">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Assign</h2></div>
			<div class="panel-body">
				{{ Form::open(array('url' => 'tickets/'.$ticket->number.'/assign', 'role' => 'form')) }}
					<div class="form-group">
						{{ Form::label('assignee_id', 'Agent', array('class' => 'sr-only')) }}
						{{ Form::select('assignee_id', $agents, $ticket->assignee_id ?: $user->id, array('class' => 'form-control')) }}
					</div>
					<button type="submit" class="btn btn-default btn-block">Assign</button>
				{{ Form::close() }}
			</div>
		</div>

		@if ($ticket->status !== 'closed')
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Priority</h2></div>
			<div class="panel-body">
				{{ Form::open(array('url' => 'tickets/'.$ticket->number.'/priority', 'role' => 'form')) }}
					<div class="form-group">
						{{ Form::label('priority', 'Priority', array('class' => 'sr-only')) }}
						{{ Form::select('priority', array_combine($priorities, $priorities), $ticket->priority, array('class' => 'form-control')) }}
					</div>
					<button type="submit" class="btn btn-default btn-block">Change priority</button>
				{{ Form::close() }}
			</div>
		</div>
		@endif

		@include('tickets._asset')

		@include('tickets._articles')

		@if (count($targets))
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Status</h2></div>
			<div class="panel-body">
				{{ Form::open(array('url' => 'tickets/'.$ticket->number.'/status', 'role' => 'form')) }}
					<div class="form-group">
						{{ Form::label('status', 'New status', array('class' => 'sr-only')) }}
						{{ Form::select('status', array_combine($targets, $targets), null, array('class' => 'form-control')) }}
					</div>
					<button type="submit" class="btn btn-default btn-block">Change status</button>
				{{ Form::close() }}
			</div>
		</div>
		@endif
	</div>
	@endif
</div>
@stop
