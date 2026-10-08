@extends('layouts.master')

@section('title', 'Knowledge base categories - Help Desk')

@section('content')
<ol class="breadcrumb">
	<li><a href="{{ URL::to('kb') }}">Knowledge base</a></li>
	<li class="active">Categories</li>
</ol>

<div class="page-header">
	<h1>Categories</h1>
</div>

<div class="row">
	<div class="col-xs-12 col-md-8">
		@if (count($categories))
		<div class="table-responsive">
			<table class="table table-striped table-condensed">
				<thead>
					<tr><th>Name</th><th>Published</th><th>Drafts</th></tr>
				</thead>
				<tbody>
					@foreach ($categories as $category)
					<?php $count = isset($totals[$category->id]) ? $totals[$category->id] : array('total' => 0, 'published' => 0); ?>
					<tr>
						<td>{{{ $category->name }}}</td>
						<td>{{ $count['published'] }}</td>
						<td>{{ $count['total'] - $count['published'] }}</td>
					</tr>
					@endforeach
				</tbody>
			</table>
		</div>
		@else
		<p class="alert alert-info">No categories yet.</p>
		@endif
	</div>

	<div class="col-xs-12 col-md-4">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Add a category</h2></div>
			<div class="panel-body">
				@include('kb._errors')
				{{ Form::open(array('url' => 'kb/categories', 'role' => 'form')) }}
					<div class="form-group {{ $errors->has('name') ? 'has-error' : '' }}">
						{{ Form::label('name', 'Name', array('class' => 'sr-only')) }}
						{{ Form::text('name', null, array('class' => 'form-control', 'maxlength' => 100, 'placeholder' => 'Name')) }}
					</div>
					<button type="submit" class="btn btn-default btn-block">Add</button>
				{{ Form::close() }}
			</div>
		</div>
	</div>
</div>
@stop
