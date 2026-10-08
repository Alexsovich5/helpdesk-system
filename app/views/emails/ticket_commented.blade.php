@include('emails._header')
		<p>{{{ $actor->name }}} added {{ $comment->is_internal ? 'an internal note' : 'a comment' }} to ticket <strong>{{{ $ticket->number }}}</strong> ({{{ $ticket->subject }}}):</p>
		<blockquote>{{ nl2br(e($comment->body)) }}</blockquote>
@include('emails._footer')
