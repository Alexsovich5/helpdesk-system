<div class="table-responsive">
	<table class="table table-striped table-condensed">
		<thead>
			<tr>
				<th>Number</th>
				<th>Subject</th>
				<th>Status</th>
				<th>Priority</th>
				<th class="hidden-xs">Requester</th>
				<th>Assignee</th>
				<th>SLA</th>
				<th class="hidden-xs">Resolution due</th>
				<th class="hidden-xs">Created</th>
			</tr>
		</thead>
		<tbody>
			@foreach ($rows as $ticket)
			<tr>
				<td><a href="{{ URL::to('tickets/'.$ticket->number) }}">{{{ $ticket->number }}}</a></td>
				<td><a href="{{ URL::to('tickets/'.$ticket->number) }}">{{{ $ticket->subject }}}</a></td>
				<td>@include('tickets._status_label', array('status' => $ticket->status))</td>
				<td>{{{ $ticket->priority }}}</td>
				<td class="hidden-xs">{{{ $ticket->requester ? $ticket->requester->name : '' }}}</td>
				<td>{{{ $ticket->assignee ? $ticket->assignee->name : '-' }}}</td>
				<td>@include('tickets._sla_badge', array('state' => $ticket->sla_state))</td>
				<td class="hidden-xs">{{{ $ticket->resolution_due_at ? $ticket->resolution_due_at->format('Y-m-d H:i') : '-' }}}</td>
				<td class="hidden-xs">{{{ $ticket->created_at->format('Y-m-d H:i') }}}</td>
			</tr>
			@endforeach
		</tbody>
	</table>
</div>
