<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
*/

// Every state-changing request needs the session's CSRF token, whatever
// filters its route lists.
Route::when('*', 'csrf', array('post', 'put', 'patch', 'delete'));

Route::pattern('number', 'HD-[0-9]+');
Route::pattern('id', '[0-9]+');

Route::get('login', array('before' => 'guest', 'uses' => 'AuthController@getLogin'));
Route::post('login', array('before' => 'guest', 'uses' => 'AuthController@postLogin'));

Route::post('logout', array('before' => 'auth', 'uses' => 'AuthController@postLogout'));
Route::get('/', array('before' => 'auth', 'uses' => 'HomeController@getIndex'));

Route::get('tickets', array('before' => 'auth', 'uses' => 'TicketController@index'));
Route::get('tickets/create', array('before' => 'auth', 'uses' => 'TicketController@create'));
Route::post('tickets', array('before' => 'auth', 'uses' => 'TicketController@store'));
Route::get('tickets/{number}', array('before' => 'auth|ticket.access', 'uses' => 'TicketController@show'));
Route::post('tickets/{number}/comments', array('before' => 'auth|ticket.access', 'uses' => 'CommentController@store'));
Route::post('tickets/{number}/assign', array('before' => 'role:agent', 'uses' => 'TicketController@assign'));
Route::post('tickets/{number}/status', array('before' => 'auth|ticket.access', 'uses' => 'TicketController@status'));
Route::post('tickets/{number}/priority', array('before' => 'role:agent', 'uses' => 'TicketController@priority'));
Route::post('tickets/{number}/asset', array('before' => 'role:agent', 'uses' => 'TicketController@linkAsset'));
Route::post('tickets/{number}/articles', array('before' => 'role:agent', 'uses' => 'TicketController@linkArticle'));

Route::get('kb', array('before' => 'auth', 'uses' => 'KbArticleController@index'));
Route::get('kb/create', array('before' => 'role:agent', 'uses' => 'KbArticleController@create'));
Route::post('kb', array('before' => 'role:agent', 'uses' => 'KbArticleController@store'));
Route::get('kb/categories', array('before' => 'role:agent', 'uses' => 'KbCategoryController@index'));
Route::post('kb/categories', array('before' => 'role:agent', 'uses' => 'KbCategoryController@store'));
Route::get('kb/{id}', array('before' => 'auth', 'uses' => 'KbArticleController@show'));
Route::get('kb/{id}/edit', array('before' => 'role:agent', 'uses' => 'KbArticleController@edit'));
Route::put('kb/{id}', array('before' => 'role:agent', 'uses' => 'KbArticleController@update'));

Route::get('assets', array('before' => 'role:agent', 'uses' => 'AssetController@index'));
Route::get('assets/create', array('before' => 'role:admin', 'uses' => 'AssetController@create'));
Route::post('assets', array('before' => 'role:admin', 'uses' => 'AssetController@store'));
Route::get('assets/{id}', array('before' => 'role:agent', 'uses' => 'AssetController@show'));
Route::get('assets/{id}/edit', array('before' => 'role:admin', 'uses' => 'AssetController@edit'));
Route::put('assets/{id}', array('before' => 'role:admin', 'uses' => 'AssetController@update'));
Route::delete('assets/{id}', array('before' => 'role:admin', 'uses' => 'AssetController@destroy'));

Route::get('reports', array('before' => 'role:agent', 'uses' => 'ReportController@index'));
Route::get('reports/export.csv', array('before' => 'role:agent', 'uses' => 'ReportController@export'));
