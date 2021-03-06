<?php


Route::get('/', 'HomeController@index');

/* API methods */
Route::get('api/v1/payments/all', 'API\PaymentController@all');
Route::get('api/v1/payments/request/{slotId}', 'API\PaymentController@request');
Route::post('api/v1/payments/request/{slotId}', 'API\PaymentController@request');
Route::get('api/v1/payments/{paymentId}', 'API\PaymentController@get');
Route::post('api/v1/payments/{paymentId}/cancel', 'API\PaymentController@cancel');

Route::get('api/v1/slots', 'API\SlotsController@all');
Route::post('api/v1/slots', 'API\SlotsController@create');
Route::get('api/v1/slots/{slotId}', 'API\SlotsController@get');
Route::patch('api/v1/slots/{slotId}', 'API\SlotsController@update');
Route::delete('api/v1/slots/{slotId}', 'API\SlotsController@delete');
Route::get('api/v1/slots/{slotId}/payments', 'API\SlotsController@payments');

Route::post('hooks/payment', array('as' => 'hooks.payment', 'uses' => 'HookController@payment'));
