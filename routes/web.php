<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    redirect('/');
});

Auth::routes();

Route::get('/browse', function () {
    redirect('/browse');
});

Route::get('/browsegroup}', function () {
    redirect('/browsegroup');
});

Route::get('/cart', function () {
    redirect('/cart');
});

Route::get('/logout', function () {
    auth()->logout();
});

Route::get('/forum', function () {
    redirect('/forum');
});

Route::get('/profile', function () {
    redirect('/profile');
});

Route::get('/movies', function () {
    redirect('/movies');

});




Route::get('/home', 'HomeController@index')->name('home');
