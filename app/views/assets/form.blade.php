@extends('layouts.master')

@section('title', ($asset->exists ? 'Edit '.$asset->asset_tag : 'New asset').' - Help Desk')

@section('content')
<div class="page-header">
	<h1>{{{ $asset->exists ? 'Edit '.$asset->asset_tag : 'New asset' }}}</h1>
</div>

<div class="row">
	<div class="col-xs-12 col-md-8">
		@include('kb._errors')

		{{ Form::model($asset, $asset->exists
			? array('url' => 'assets/'.$asset->id, 'method' => 'put', 'role' => 'form')
			: array('url' => 'assets', 'role' => 'form')) }}
			<div class="row">
				<div class="col-xs-12 col-sm-4 form-group {{ $errors->has('asset_tag') ? 'has-error' : '' }}">
					{{ Form::label('asset_tag', 'Asset tag', array('class' => 'control-label')) }}
					{{ Form::text('asset_tag', null, array('class' => 'form-control', 'maxlength' => Asset::$maxLengths['asset_tag'])) }}
				</div>
				<div class="col-xs-12 col-sm-8 form-group {{ $errors->has('name') ? 'has-error' : '' }}">
					{{ Form::label('name', 'Name', array('class' => 'control-label')) }}
					{{ Form::text('name', null, array('class' => 'form-control', 'maxlength' => Asset::$maxLengths['name'])) }}
				</div>
			</div>
			<div class="row">
				<div class="col-xs-12 col-sm-4 form-group {{ $errors->has('type') ? 'has-error' : '' }}">
					{{ Form::label('type', 'Type', array('class' => 'control-label')) }}
					{{ Form::text('type', null, array('class' => 'form-control', 'maxlength' => Asset::$maxLengths['type'], 'placeholder' => 'laptop, printer, phone...')) }}
				</div>
				<div class="col-xs-12 col-sm-4 form-group {{ $errors->has('serial') ? 'has-error' : '' }}">
					{{ Form::label('serial', 'Serial number', array('class' => 'control-label')) }}
					{{ Form::text('serial', null, array('class' => 'form-control', 'maxlength' => Asset::$maxLengths['serial'])) }}
				</div>
				<div class="col-xs-12 col-sm-4 form-group {{ $errors->has('status') ? 'has-error' : '' }}">
					{{ Form::label('status', 'Status', array('class' => 'control-label')) }}
					{{ Form::select('status', array_combine($statuses, str_replace('_', ' ', $statuses)), null, array('class' => 'form-control')) }}
				</div>
			</div>
			<div class="row">
				<div class="col-xs-12 col-sm-6 form-group {{ $errors->has('location') ? 'has-error' : '' }}">
					{{ Form::label('location', 'Location', array('class' => 'control-label')) }}
					{{ Form::text('location', null, array('class' => 'form-control', 'maxlength' => Asset::$maxLengths['location'])) }}
				</div>
				<div class="col-xs-12 col-sm-6 form-group {{ $errors->has('assigned_user_id') ? 'has-error' : '' }}">
					{{ Form::label('assigned_user_id', 'Assigned to', array('class' => 'control-label')) }}
					{{ Form::select('assigned_user_id', $users, null, array('class' => 'form-control')) }}
				</div>
			</div>
			<button type="submit" class="btn btn-primary">Save</button>
			<a href="{{ URL::to($asset->exists ? 'assets/'.$asset->id : 'assets') }}" class="btn btn-link">Cancel</a>
		{{ Form::close() }}
	</div>
</div>
@stop
