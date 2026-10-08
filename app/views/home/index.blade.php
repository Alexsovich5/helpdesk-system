@extends('layouts.master')

@section('content')
<div class="page-header">
	<h1>Dashboard <small>{{{ $user->name }}}</small></h1>
</div>

<div class="row">
	<div class="col-xs-12 col-md-8">
		<p class="lead">Signed in as <strong>{{{ $user->username }}}</strong> ({{{ $user->role }}}).</p>
	</div>
</div>
@stop
