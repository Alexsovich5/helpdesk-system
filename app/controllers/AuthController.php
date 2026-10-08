<?php

class AuthController extends BaseController {

	public function getLogin()
	{
		return View::make('auth.login');
	}

	public function postLogin()
	{
		$validator = Validator::make(Input::only('username', 'password'), array(
			'username' => 'required',
			'password' => 'required',
		));

		if ($validator->fails())
		{
			return Redirect::to('login')->withErrors($validator)->withInput(Input::except('password'));
		}

		$credentials = array(
			'username' => Input::get('username'),
			'password' => Input::get('password'),
		);

		if (is_string($credentials['username']) && is_string($credentials['password'])
			&& Auth::attempt($credentials, (bool) Input::get('remember')))
		{
			return Redirect::intended('/');
		}

		return Redirect::to('login')
			->withErrors(array('username' => 'The username or password is incorrect.'))
			->withInput(Input::except('password'));
	}

	public function postLogout()
	{
		Auth::logout();

		return Redirect::to('login');
	}

}
