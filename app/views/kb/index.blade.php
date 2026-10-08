@extends('layouts.master')

@section('title', 'Knowledge base - Help Desk')

@section('content')
<div class="page-header">
	@if ($user->isAgent())
	<div class="pull-right">
		<a href="{{ URL::to('kb/categories') }}" class="btn btn-default">Categories</a>
		<a href="{{ URL::to('kb/create') }}" class="btn btn-primary">New article</a>
	</div>
	@endif
	<h1>Knowledge base</h1>
</div>

{{ Form::open(array('url' => 'kb', 'method' => 'get', 'class' => 'kb-search', 'role' => 'search')) }}
	<div class="input-group">
		{{ Form::label('q', 'Search articles', array('class' => 'sr-only')) }}
		{{ Form::text('q', $q, array('class' => 'form-control', 'placeholder' => 'Search titles and text')) }}
		<span class="input-group-btn">
			<button type="submit" class="btn btn-default">Search</button>
		</span>
	</div>
{{ Form::close() }}

@if ($articles->count())
<div class="table-responsive">
	<table class="table table-striped table-condensed">
		<thead>
			<tr>
				<th>Title</th>
				<th class="hidden-xs">Category</th>
				@if ($user->isAgent())<th>State</th>@endif
				<th class="hidden-xs">Updated</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($articles as $article)
			<tr>
				<td><a href="{{ URL::to('kb/'.$article->id) }}">{{{ $article->title }}}</a></td>
				<td class="hidden-xs">{{{ $article->category ? $article->category->name : '' }}}</td>
				@if ($user->isAgent())
				<td>@if ($article->is_published)<span class="label label-success">Published</span>@else<span class="label label-default">Draft</span>@endif</td>
				@endif
				<td class="hidden-xs">{{{ $article->updated_at->format('Y-m-d') }}}</td>
			</tr>
			@endforeach
		</tbody>
	</table>
</div>
<p class="text-muted">Showing {{ $articles->getFrom() }}-{{ $articles->getTo() }} of {{ $articles->getTotal() }}.</p>
{{ $articles->links() }}
@elseif ($q !== '')
<p class="alert alert-info">No articles contain every word of "{{{ $q }}}".</p>
@else
<p class="alert alert-info">There are no articles yet.</p>
@endif
@stop
