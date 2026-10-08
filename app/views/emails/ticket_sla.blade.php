@include('emails._header')
@if ($state === 'breached')
		<p>Ticket <strong>{{{ $ticket->number }}}</strong> ({{{ $ticket->subject }}}) has breached its SLA.</p>
@else
		<p>Ticket <strong>{{{ $ticket->number }}}</strong> ({{{ $ticket->subject }}}) has used 80% or more of its SLA window.</p>
@endif
		<table cellpadding="4">
			<tr><td>Priority</td><td>{{{ $ticket->priority }}}</td></tr>
			<tr><td>Status</td><td>{{{ $ticket->status }}}</td></tr>
			<tr><td>Assignee</td><td>{{{ $ticket->assignee ? $ticket->assignee->name : 'unassigned' }}}</td></tr>
			<tr><td>Response due</td><td>{{{ $ticket->response_due_at ? $ticket->response_due_at->format('Y-m-d H:i') : '-' }}}{{ $ticket->first_responded_at ? ' (responded)' : '' }}</td></tr>
			<tr><td>Resolution due</td><td>{{{ $ticket->resolution_due_at ? $ticket->resolution_due_at->format('Y-m-d H:i') : '-' }}}</td></tr>
		</table>
@include('emails._footer')
