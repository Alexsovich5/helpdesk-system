@include('emails._header')
		<p>Ticket <strong>{{{ $ticket->number }}}</strong> has been logged.</p>
		<table cellpadding="4">
			<tr><td>Subject</td><td>{{{ $ticket->subject }}}</td></tr>
			<tr><td>Requester</td><td>{{{ $ticket->requester->name }}}</td></tr>
			<tr><td>Category</td><td>{{{ $ticket->category ? $ticket->category->name : '' }}}</td></tr>
			<tr><td>Priority</td><td>{{{ $ticket->priority }}}</td></tr>
		</table>
		<p>{{ nl2br(e($ticket->description)) }}</p>
@include('emails._footer')
