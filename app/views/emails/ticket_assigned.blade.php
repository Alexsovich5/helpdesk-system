@include('emails._header')
		<p>{{{ $actor->name }}} assigned ticket <strong>{{{ $ticket->number }}}</strong> to you.</p>
		<table cellpadding="4">
			<tr><td>Subject</td><td>{{{ $ticket->subject }}}</td></tr>
			<tr><td>Requester</td><td>{{{ $ticket->requester->name }}}</td></tr>
			<tr><td>Priority</td><td>{{{ $ticket->priority }}}</td></tr>
			<tr><td>Status</td><td>{{{ $ticket->status }}}</td></tr>
		</table>
@include('emails._footer')
