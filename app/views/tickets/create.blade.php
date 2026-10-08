@extends('layouts.master')

@section('title', 'New ticket - Help Desk')

@section('content')
<div class="page-header">
	<h1>New ticket</h1>
</div>

<div class="row">
	<div class="col-xs-12 col-md-8">
		@if ($errors->any())
		<div class="alert alert-danger">
			<ul class="list-unstyled">
				@foreach ($errors->all() as $message)
				<li>{{{ $message }}}</li>
				@endforeach
			</ul>
		</div>
		@endif

		{{ Form::open(array('url' => 'tickets', 'role' => 'form')) }}
			<div class="form-group {{ $errors->has('subject') ? 'has-error' : '' }}">
				{{ Form::label('subject', 'Subject', array('class' => 'control-label')) }}
				{{ Form::text('subject', null, array('class' => 'form-control', 'maxlength' => 255)) }}
			</div>
			<div class="row">
				<div class="col-xs-12 col-sm-6 form-group {{ $errors->has('category_id') ? 'has-error' : '' }}">
					{{ Form::label('category_id', 'Category', array('class' => 'control-label')) }}
					{{ Form::select('category_id', $categories, null, array('class' => 'form-control')) }}
				</div>
				<div class="col-xs-12 col-sm-6 form-group {{ $errors->has('priority') ? 'has-error' : '' }}">
					{{ Form::label('priority', 'Priority', array('class' => 'control-label')) }}
					{{ Form::select('priority', array_combine($priorities, $priorities), 'normal', array('class' => 'form-control')) }}
				</div>
			</div>
			@if (count($assets) > 1)
			<div class="form-group {{ $errors->has('asset_id') ? 'has-error' : '' }}">
				{{ Form::label('asset_id', 'Affected device', array('class' => 'control-label')) }}
				{{ Form::select('asset_id', $assets, null, array('class' => 'form-control')) }}
			</div>
			@endif
			<div class="form-group {{ $errors->has('description') ? 'has-error' : '' }}">
				{{ Form::label('description', 'Description', array('class' => 'control-label')) }}
				{{ Form::textarea('description', null, array('class' => 'form-control', 'rows' => 8)) }}
			</div>
			<button type="submit" class="btn btn-primary btn-block-xs">Create ticket</button>
			<a href="{{ URL::to('tickets') }}" class="btn btn-link btn-block-xs">Cancel</a>
		{{ Form::close() }}
	</div>
</div>
@stop
