@extends('layouts.master')

@section('title', $article->title.' - Knowledge base - Help Desk')

@section('content')
<ol class="breadcrumb">
	<li><a href="{{ URL::to('kb') }}">Knowledge base</a></li>
	<li class="active">{{{ $article->category ? $article->category->name : '' }}}</li>
</ol>

<div class="page-header">
	@if ($user->isAgent())
	<a href="{{ URL::to('kb/'.$article->id.'/edit') }}" class="btn btn-default pull-right">Edit</a>
	@endif
	<h1>{{{ $article->title }}} @if ( ! $article->is_published)<span class="label label-default">Draft</span>@endif</h1>
	<p class="text-muted small">
		{{{ $article->author ? $article->author->name : '' }}} &middot; updated {{{ $article->updated_at->format('Y-m-d H:i') }}}
	</p>
</div>

<div class="kb-body">{{ nl2br(e($article->body)) }}</div>
@stop
