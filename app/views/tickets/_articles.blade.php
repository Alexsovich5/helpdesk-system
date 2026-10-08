<div class="panel panel-default ticket-articles">
	<div class="panel-heading"><h2 class="panel-title">Knowledge base</h2></div>
	@if (count($articles))
	<ul class="list-group">
		@foreach ($articles as $article)
		<li class="list-group-item"><a href="{{ URL::to('kb/'.$article->id) }}">{{{ $article->title }}}</a></li>
		@endforeach
	</ul>
	@elseif ( ! $user->isAgent())
	<div class="panel-body text-muted">No articles linked yet.</div>
	@endif
	@if ($user->isAgent())
	<div class="panel-body">
		@if (count($articleOptions))
		{{ Form::open(array('url' => 'tickets/'.$ticket->number.'/articles', 'role' => 'form')) }}
			<div class="form-group {{ $errors->has('kb_article_id') ? 'has-error' : '' }}">
				{{ Form::label('kb_article_id', 'Article', array('class' => 'sr-only')) }}
				{{ Form::select('kb_article_id', $articleOptions, null, array('class' => 'form-control')) }}
			</div>
			<button type="submit" class="btn btn-default btn-block">Link article</button>
		{{ Form::close() }}
		@else
		<p class="text-muted">No other published articles to link.</p>
		@endif
		<p class="small"><a href="{{ URL::to('kb') }}">Search the knowledge base</a></p>
	</div>
	@endif
</div>
