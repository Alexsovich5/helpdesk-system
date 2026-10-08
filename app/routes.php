<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
*/

Route::get('login', array('before' => 'guest', 'uses' => 'AuthController@getLogin'));
Route::post('login', array('before' => 'guest|csrf', 'uses' => 'AuthController@postLogin'));

Route::group(array('before' => 'auth'), function()
{
	Route::get('logout', 'AuthController@getLogout');
	Route::get('/', 'HomeController@getIndex');
});
