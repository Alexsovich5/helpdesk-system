@include('emails._header')
		<p>{{{ $actor->name }}} changed the status of ticket <strong>{{{ $ticket->number }}}</strong> ({{{ $ticket->subject }}}) from <strong>{{{ $from }}}</strong> to <strong>{{{ $to }}}</strong>.</p>
@if ($to === 'resolved')
		<p>If the problem is not fixed you can reopen the ticket from its page.</p>
@endif
@include('emails._footer')
