<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title">By {{{ $label }}}</h2></div>
	<div class="table-responsive">
		<table class="table table-condensed report-breakdown">
			<tbody>
				@foreach ($counts as $name => $count)
				<tr>
					<th scope="row">{{{ str_replace('_', ' ', $name) }}}</th>
					<td class="text-right">{{ $count }}</td>
					<td class="report-bar">
						<div class="progress">
							<div class="progress-bar" role="progressbar" aria-valuenow="{{ $count }}" aria-valuemin="0" aria-valuemax="{{ $total }}" style="width: {{ round(100 * $count / $total) }}%"></div>
						</div>
					</td>
				</tr>
				@endforeach
			</tbody>
		</table>
	</div>
</div>
