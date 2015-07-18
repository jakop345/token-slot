<?php

namespace App\Http\Controllers\API;
use App\Http\Controllers\API\Base\APIController;
use Exception;
use Illuminate\Http\JsonResponse;
use User, Slot, Input, Response, Payment, Config;

class PaymentController extends APIController {
	
	/**
	 * initiate a payment request
	 * @param string $slotId the public_id of the payment "slot"
	 * @return Response
	 * */
	public function request($slotId)
	{
		$user = User::$api_user;
		$input = Input::all();
		$output = array();
		$time = timestamp();
		//check if this is a legit slot
		$getSlot = Slot::where('userId', '=', $user->id)
						 ->where('public_id', '=', $slotId)
						 ->orWhere('nickname', '=', $slotId)
						 ->first();
		if(!$getSlot){
            $message = "Invalid slot ID";
			return Response::json(array('error' => $message), 400);
		}
		
		$getSlot->tokens = json_decode($getSlot->tokens, true);
		if(!is_array($getSlot->tokens)){
            $message = "Slot accepted token list invalid";
			return Response::json(array('error' => $message), 400);
		}
		
		if(!isset($input['token'])){
            $message = "Payment token name required";
			return Response::json(array('error' => $message), 400);
		}
		
		if((isset($input['token']) AND !in_array(strtoupper(trim($input['token'])), $getSlot->tokens))){
            $message = "Token ".$input['token']." not accepted by this slot";
			return Response::json(array('error' => $message), 400);	
		}
		$input['token'] = strtoupper(trim($input['token']));
		
		/*** Begin pegging code ***/
		//validators for peg options
		//start with the valid flags FALSE
		//we will validate if we receive these options in the input
		$valid_peg = FALSE;
		$valid_peg_total = FALSE;
		$valid_peg_calculated = FALSE;
		$peg = '';
		$peg_total = '';
		
		if(isset($input['peg'])){
			$peg = strtoupper(trim($input['peg']));
			if($peg == 'USD'){
				$valid_peg = TRUE;
			}
			else{
				$message = "Pegging API only supports USD, ".$peg." is invalid";
				return Response::json(array('error' => $message), 400);
			}
		}

		if(isset($input['peg_total'])){
			$peg_total = intval($input['peg_total']);
			if($peg_total > 0){
				$valid_peg_total = TRUE;                        
			}
		}

		if($valid_peg === TRUE AND $valid_peg_total === TRUE){
			//the list of tokens we can peg to USD
			$peg_tokens_list = explode(',', Config::get('settings.peggable_tokens'));

			//make sure it's a token we can peg
			if(!in_array($input['token'],$peg_tokens_list)){
				$message = 'Pegging not supported with '.$input['token'].'. Supported tokens: '.join(', ', $peg_tokens_list);
				return Response::json(array('error' => $message), 400);
			}

			$quotebot_url = env('QUOTEBOT_URL','http://localhost');

			//we pull real time price data from quotebot
			$quotebot_response = file($quotebot_url);
			$quotebot_json_data = json_decode($quotebot_response[0]);
			if(!is_object($quotebot_json_data)){
				$message = 'Error retrieving token price quotes';
				return Response::json(array('error' => $message), 400);
			}
			$quotes = $quotebot_json_data->{'quotes'};

			foreach($quotes as $quote){
				//first find the USD:BTC price in cents
				if($quote->{'source'} == 'bitcoinAverage'){
					$usd_btc_cents = $quote->{'last'} * 100;
				}
				//now find the BTC price for our token
				list($payment_currency,$order_currency) = explode(':',$quote->{'pair'});
				if($order_currency == $input['token']){
					//find the BTC satoshis for our peg total
					$btc_satoshis = ($peg_total/$usd_btc_cents) * 100000000;
					$token_price_satoshis = $quote->{'last'};
					//finally, figure out satoshis of the token
					$pegged_satoshis = intval(($btc_satoshis / $token_price_satoshis * 100000000));
				}
			}
			//this line feeds a value into the "total" processing code about 20 lines down
			$input['total'] = $pegged_satoshis;
		}
		elseif($valid_peg === TRUE AND $valid_peg_total === FALSE){
			$message = "Gave a valid peg, but peg_total ".$peg_total." is invalid";
			return Response::json(array('error' => $message), 400);
		}
		elseif($valid_peg === FALSE AND $valid_peg_total === TRUE){
			$message = "Gave a valid peg_total, but peg ".$peg." is invalid";
			return Response::json(array('error' => $message), 400);
		}
		else{
			//no peg options given, do nothing
		}
		/*** End pegging code ***/

		//initialize xchain client
		$xchain = xchain();
		try{
			$address = $xchain->newPaymentAddress();
			$monitor = $xchain->newAddressMonitor($address['address'], route('hooks.payment').'?nonce='.strtotime($time).$getSlot->id);
		}
		catch(Exception $e){
			return Response::json(array('error' => 'Error generating payment request'), 500);
		}
		
		$total = 0; //allow for 0 total for "pay what you want" type situations
		//the pegging code about 20 lines above feeds into here when valid peg input is provided
		//totals should be in satoshis (or just plain number if non-divisible asset)
		if(isset($input['total'])){
			$total = intval($input['total']);
			if($total < 0){
				return Response::json(array('error' => 'Invalid total'), 400);
			}
		}
		
		$ref = ''; 
		if(isset($input['reference'])){
			$input['ref'] = $input['reference'];
		}
		if(isset($input['ref'])){ //user assigned reference
			$ref = trim($input['ref']);
		}
		
		//save the payment data
		$payment = new Payment;
		$payment->slotId = $getSlot->id;
		$payment->address = $address['address']; 
		$payment->token = $input['token'];
		$payment->total = $total;
		$payment->init_date = $time;
		$payment->IP = $_SERVER['REMOTE_ADDR'];
		$payment->reference = substr($ref, 0, 64);  //limit to 64 characters
		$payment->payment_uuid = $address['id']; //xchain references
		$payment->monitor_uuid = $monitor['id'];
		try{
			$save = $payment->save();
		}
		catch(Exception $e){
            $message = "Failed to create payment request";
			return Response::json(array('error' => $message), 500);
		}
		
		//setup the response
		$output['payment_id'] = $payment->id;
		$output['address'] = $payment->address;
		//optional code to provide the pegged tokens if valid peg input was given
		if($valid_peg === TRUE AND $valid_peg_total === TRUE){
		        $output['total'] = $total;
		}

		
		return Response::json($output);
	}
	
	/*
	 * gets data for a specific payment request
	 * @param mixed $paymentId the ID, "reference" or bitcoin address of a payment_request
	 * @return Response
	 * */
	public function get($paymentId)
	{
		$user = User::$api_user;
		$slots = Slot::where('userId', '=', $user->id)->get();

		$getPayment = Payment::getPayment($paymentId);
		if(!$getPayment){
            $message = "Invalid payment ID";
			return Response::json(array('error' => $message), 400);
		}
		
		$thisSlot = false;
		foreach($slots as $s){
			if($s->id == $getPayment->slotId){
				$thisSlot = $s;
				break;
			}
		}
		
		$getPayment->id = intval($getPayment->id);
		unset($getPayment->slotId);
		$getPayment->slot_id = $thisSlot->public_id;
		$getPayment->total = intval($getPayment->total);
		$getPayment->received = intval($getPayment->received);
		$getPayment->complete = boolval($getPayment->complete);
		$getPayment->tx_info = json_decode($getPayment->tx_info);
		$getPayment->cancelled = boolval($getPayment->cancelled);
		
		return Response::json($getPayment);
	}
	
	/**
	 * cancels a payment request and sets the xchain address monitor to inactive.
	 * @param mixed $paymentId the ID, "reference" or bitcoin address of a payment_request
	 * @return Response
	 * */
	public function cancel($paymentId)
	{
		$output = array('result' => false);
		$getPayment = Payment::getPayment($paymentId);
		if($getPayment->cancelled == 1){
			$output['error'] = 'Payment already cancelled';
			return Response::json($output, 400);
		}
		$xchain = xchain();
		try{
			$xchain->updateAddressMonitorActiveState($getPayment->monitor_uuid, false);
		}
		catch(\Exception $e){
			$output['error'] = 'Error canceling payment request';
			return Response::json($output, 500);
		}
		$getPayment->cancelled = 1;
		$getPayment->cancel_time = timestamp();
		$getPayment->save();
		$output['result'] = true;
		return Response::json($output);
	}
	
	/**
	 * returns a list of all payment requests tied to this clients account
	 * @return Response
	 * */
	public function all()
	{
		$output = array();
		$user = User::$api_user;
		$input = Input::all();
		$slots = Slot::where('userId', '=', $user->id)->get();
		$valid_slots = array();
		if($slots){
			foreach($slots as $slot){
				$valid_slots[] = $slot->id;
			}
		}
		
		if(count($valid_slots) == 0){
			$output = array('error' => 'Please create a slot first');
			return Response::json($output, 400);
		}
		$payments = Payment::whereIn('slotId', $valid_slots);
		if(isset($input['incomplete'])){
			if(boolval($input['incomplete'])){
				$andComplete = true;
			}
			else{
				$andComplete = false;
			}
			$payments = $payments->where('complete', '=', $andComplete);
		}
		$andCancel = false;
		if(isset($input['cancelled'])){
			if(boolval($input['cancelled'])){
				$andCancel = true;
			}
		}
		if(!$andCancel){
			$payments = $payments->where('cancelled', '!=', '1');
		}
		
		$payments = $payments->select('id', 'address', 'token', 'total', 'received', 'complete', 'init_date', 'complete_date',
							 'reference', 'tx_info', 'slotId as slot_id', 'cancelled', 'cancel_time')->get();
					
		foreach($payments as &$payment){
			$payment->tx_info = json_decode($payment->tx_info);
			$payment->total = intval($payment->total);
			$payment->received = intval($payment->received);
			$payment->complete = boolval($payment->complete);
			$payment->id = intval($payment->id);
			$payment->cancelled = boolval($payment->cancelled);
			foreach($slots as $slot){
				if($slot->id == $payment->slot_id){
					$payment->slot_id = $slot->public_id;
				}
			}
		}
		return Response::json($payments);
	}	
}

