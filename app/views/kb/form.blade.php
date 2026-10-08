@extends('layouts.master')

@section('title', ($article->exists ? 'Edit article' : 'New article').' - Help Desk')

@section('content')
<div class="page-header">
	<h1>{{ $article->exists ? 'Edit article' : 'New article' }}</h1>
</div>

<div class="row">
	<div class="col-xs-12 col-md-8">
		@include('kb._errors')

		@if ( ! count($categories))
		<p class="alert alert-warning">Add a <a href="{{ URL::to('kb/categories') }}">category</a> before writing articles.</p>
		@endif

		{{ Form::model($article, $article->exists
			? array('url' => 'kb/'.$article->id, 'method' => 'put', 'role' => 'form')
			: array('url' => 'kb', 'role' => 'form')) }}
			<div class="form-group {{ $errors->has('title') ? 'has-error' : '' }}">
				{{ Form::label('title', 'Title', array('class' => 'control-label')) }}
				{{ Form::text('title', null, array('class' => 'form-control', 'maxlength' => 255)) }}
			</div>
			<div class="form-group {{ $errors->has('kb_category_id') ? 'has-error' : '' }}">
				{{ Form::label('kb_category_id', 'Category', array('class' => 'control-label')) }}
				{{ Form::select('kb_category_id', $categories, null, array('class' => 'form-control')) }}
			</div>
			<div class="form-group {{ $errors->has('body') ? 'has-error' : '' }}">
				{{ Form::label('body', 'Text', array('class' => 'control-label')) }}
				{{ Form::textarea('body', null, array('class' => 'form-control', 'rows' => 14)) }}
				<p class="help-block">Plain text; line breaks are kept.</p>
			</div>
			<div class="checkbox">
				<label>{{ Form::checkbox('is_published', '1') }} Published (visible to everyone)</label>
			</div>
			<button type="submit" class="btn btn-primary">Save</button>
			<a href="{{ URL::to($article->exists ? 'kb/'.$article->id : 'kb') }}" class="btn btn-link">Cancel</a>
		{{ Form::close() }}
	</div>
</div>
@stop
