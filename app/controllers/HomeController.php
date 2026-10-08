<?php

class HomeController extends BaseController {

	public function getIndex()
	{
		return View::make('home.index', array('user' => Auth::user()));
	}

}
