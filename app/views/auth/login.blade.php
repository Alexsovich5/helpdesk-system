@extends('layouts.master')

@section('title', 'Sign in - Help Desk')

@section('content')
<div class="row">
	<div class="col-xs-12 col-sm-8 col-sm-offset-2 col-md-6 col-md-offset-3">
		<div class="panel panel-default login-panel">
			<div class="panel-heading"><h1 class="panel-title">Sign in</h1></div>
			<div class="panel-body">
				@if ($errors->any())
				<div class="alert alert-danger">
					<ul class="list-unstyled">
						@foreach ($errors->all() as $message)
						<li>{{{ $message }}}</li>
						@endforeach
					</ul>
				</div>
				@endif

				{{ Form::open(array('url' => 'login', 'role' => 'form')) }}
					<div class="form-group {{ $errors->has('username') ? 'has-error' : '' }}">
						{{ Form::label('username', 'Username', array('class' => 'control-label')) }}
						{{ Form::text('username', Input::old('username'), array('class' => 'form-control', 'autofocus', 'autocapitalize' => 'off', 'autocorrect' => 'off')) }}
					</div>
					<div class="form-group {{ $errors->has('password') ? 'has-error' : '' }}">
						{{ Form::label('password', 'Password', array('class' => 'control-label')) }}
						{{ Form::password('password', array('class' => 'form-control')) }}
					</div>
					<div class="checkbox">
						<label>{{ Form::checkbox('remember', 1) }} Keep me signed in</label>
					</div>
					<button type="submit" class="btn btn-primary btn-block">Sign in</button>
				{{ Form::close() }}
			</div>
		</div>
	</div>
</div>
@stop
