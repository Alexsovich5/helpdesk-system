@extends('layouts.master')

@section('content')
<div class="page-header">
	<a href="{{ URL::to('tickets/create') }}" class="btn btn-primary pull-right">New ticket</a>
	<h1>Dashboard <small>{{{ $user->name }}}</small></h1>
</div>

<p class="lead">Signed in as <strong>{{{ $user->username }}}</strong> ({{{ $user->role }}}).</p>

@if ($user->isAgent())
<h2 class="h3">Assigned to me</h2>
@if (count($assigned))
	@include('tickets._table', array('rows' => $assigned))
@else
	<p class="text-muted">No open tickets are assigned to you.</p>
@endif

<h2 class="h3">Unassigned queue</h2>
@if (count($queue))
	@include('tickets._table', array('rows' => $queue))
@else
	<p class="text-muted">The queue is empty.</p>
@endif
@endif

<h2 class="h3">My open tickets</h2>
@if (count($mine))
	@include('tickets._table', array('rows' => $mine))
@else
	<p class="text-muted">You have no open tickets.</p>
@endif
@stop
