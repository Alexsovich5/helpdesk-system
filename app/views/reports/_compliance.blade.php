<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title">{{{ $label }}} SLA compliance</h2></div>
	<div class="panel-body">
		@if (is_null($figures['percent']))
		<p class="text-muted">Nothing to measure yet: no {{{ strtolower($label) }}} clock has stopped or passed its due time.</p>
		@else
		<p class="report-figure">{{ number_format($figures['percent'], 1) }}%</p>
		<div class="progress">
			<div class="progress-bar {{ $figures['percent'] >= 90 ? 'progress-bar-success' : ($figures['percent'] >= 70 ? 'progress-bar-warning' : 'progress-bar-danger') }}" role="progressbar" aria-valuenow="{{ $figures['percent'] }}" aria-valuemin="0" aria-valuemax="100" style="width: {{ $figures['percent'] }}%"></div>
		</div>
		<p class="text-muted">{{ $figures['met'] }} of {{ $figures['measured'] }} met their target.</p>
		@endif
	</div>
</div>
