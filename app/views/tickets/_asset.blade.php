<div class="panel panel-default ticket-asset">
	<div class="panel-heading"><h2 class="panel-title">Asset</h2></div>
	<div class="panel-body">
		@if ($ticket->asset)
		<p><a href="{{ URL::to('assets/'.$ticket->asset->id) }}">{{{ $ticket->asset->label() }}}</a></p>
		@endif
		@if (count($assetOptions))
		{{ Form::open(array('url' => 'tickets/'.$ticket->number.'/asset', 'role' => 'form')) }}
			<div class="form-group {{ $errors->has('asset_id') ? 'has-error' : '' }}">
				{{ Form::label('asset_id', 'Asset', array('class' => 'sr-only')) }}
				{{ Form::select('asset_id', $assetOptions, $ticket->asset_id, array('class' => 'form-control')) }}
			</div>
			<button type="submit" class="btn btn-default btn-block">{{ $ticket->asset ? 'Change asset' : 'Link asset' }}</button>
		{{ Form::close() }}
		@else
		<p class="text-muted">The asset register is empty.</p>
		@endif
	</div>
</div>
