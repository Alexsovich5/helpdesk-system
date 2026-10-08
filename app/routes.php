<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
*/

Route::pattern('number', 'HD-[0-9]+');
Route::pattern('id', '[0-9]+');

Route::get('login', array('before' => 'guest', 'uses' => 'AuthController@getLogin'));
Route::post('login', array('before' => 'guest|csrf', 'uses' => 'AuthController@postLogin'));

Route::get('logout', array('before' => 'auth', 'uses' => 'AuthController@getLogout'));
Route::get('/', array('before' => 'auth', 'uses' => 'HomeController@getIndex'));

Route::get('tickets', array('before' => 'auth', 'uses' => 'TicketController@index'));
Route::get('tickets/create', array('before' => 'auth', 'uses' => 'TicketController@create'));
Route::post('tickets', array('before' => 'auth|csrf', 'uses' => 'TicketController@store'));
Route::get('tickets/{number}', array('before' => 'auth|ticket.access', 'uses' => 'TicketController@show'));
Route::post('tickets/{number}/comments', array('before' => 'auth|ticket.access|csrf', 'uses' => 'CommentController@store'));
Route::post('tickets/{number}/assign', array('before' => 'role:agent|csrf', 'uses' => 'TicketController@assign'));
Route::post('tickets/{number}/status', array('before' => 'auth|ticket.access|csrf', 'uses' => 'TicketController@status'));
Route::post('tickets/{number}/priority', array('before' => 'role:agent|csrf', 'uses' => 'TicketController@priority'));
Route::post('tickets/{number}/articles', array('before' => 'role:agent|csrf', 'uses' => 'TicketController@linkArticle'));

Route::get('kb', array('before' => 'auth', 'uses' => 'KbArticleController@index'));
Route::get('kb/create', array('before' => 'role:agent', 'uses' => 'KbArticleController@create'));
Route::post('kb', array('before' => 'role:agent|csrf', 'uses' => 'KbArticleController@store'));
Route::get('kb/categories', array('before' => 'role:agent', 'uses' => 'KbCategoryController@index'));
Route::post('kb/categories', array('before' => 'role:agent|csrf', 'uses' => 'KbCategoryController@store'));
Route::get('kb/{id}', array('before' => 'auth', 'uses' => 'KbArticleController@show'));
Route::get('kb/{id}/edit', array('before' => 'role:agent', 'uses' => 'KbArticleController@edit'));
Route::put('kb/{id}', array('before' => 'role:agent|csrf', 'uses' => 'KbArticleController@update'));
