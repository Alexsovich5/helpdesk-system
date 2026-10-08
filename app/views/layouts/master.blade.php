<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>@yield('title', 'Help Desk')</title>
	<link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}">
	<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
	<nav class="navbar navbar-default navbar-static-top" role="navigation">
		<div class="container">
			<div class="navbar-header">
				<button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#main-nav">
					<span class="sr-only">Toggle navigation</span>
					<span class="icon-bar"></span>
					<span class="icon-bar"></span>
					<span class="icon-bar"></span>
				</button>
				<a class="navbar-brand" href="{{ URL::to('/') }}">Help Desk</a>
			</div>
			<div class="collapse navbar-collapse" id="main-nav">
				@if (Auth::check())
				<ul class="nav navbar-nav">
					<li class="{{ Request::is('/') ? 'active' : '' }}"><a href="{{ URL::to('/') }}">Dashboard</a></li>
				</ul>
				<ul class="nav navbar-nav navbar-right">
					<li><p class="navbar-text">{{{ Auth::user()->name }}} <span class="label label-default">{{{ Auth::user()->role }}}</span></p></li>
					<li><a href="{{ URL::to('logout') }}">Sign out</a></li>
				</ul>
				@endif
			</div>
		</div>
	</nav>

	<div class="container">
		@if (Session::has('status'))
		<div class="alert alert-success">{{{ Session::get('status') }}}</div>
		@endif

		@yield('content')
	</div>

	<script src="{{ asset('js/jquery-1.11.1.min.js') }}"></script>
	<script src="{{ asset('vendor/bootstrap/js/bootstrap.min.js') }}"></script>
	@yield('scripts')
</body>
</html>
