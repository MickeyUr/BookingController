<?php

namespace App\Http\Controllers\API;

use App\ActivitySpace;
use App\Booking;
use App\BookingItem;
use App\BookingMessage;
use App\CustomQuery;
use App\Http\Controllers\Controller;
use App\Mail\Host\HostGuestCancelsBooking;
use App\Mail\Host\HostNewBookingRequest;
use App\Mail\Host\HostSpaceBookingCompleted;
use App\Mail\Host\HostUpdateBookingRequest;
use App\Mail\Tenant\TenantBookingRequestCanceled;
use App\Mail\Tenant\TenantBookingRequestCompleted;
use App\Mail\Tenant\TenantBookingRequestConfirmed;
use App\Mail\Tenant\TenantBookingRequestDenied;
use App\Mail\Tenant\TenantUpdateBookingRequest;
use App\Mail\Tenant\TenantBookingRequestSent;
use App\Payments\Controllers\StripeController;
use App\Payments\Transaction;
use App\RequestMessages;
use App\Requests;
use App\SecurityDeposite;
use App\Space;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use App\Http\Controllers\CustomQueryController;


class BookingController extends Controller
{
    public function request(Request $request)
    {
        try {
            $stripe = new StripeController;
            $customquery = new CustomQueryController();

            $user = auth('api')->user();
            if ($user) {
                $space = Space::where(['id' => $request->placeId])->first();
                $owner = $space->owner;
                $request->request->set('phone', $user->phoneNumber);
                $request->request->set('email', $user->email);

                $explodeExpireDate = explode('/', $request->expiryDate);

                $request->request->set('expMonth', $explodeExpireDate[0]);
                $request->request->set('expYear', $explodeExpireDate[1]);

                if (!$user->stripeCustomerId) {
                    $customer = $stripe->createCustomer($request);
                    $customerId = $customer->id;
                } else {
                    $customer = Customer::retrieve($user->stripeCustomerId);
                    $customerId = $user->stripeCustomerId;
                }

                $deposit = 0;

                $datedetails = '';

                $booking = Booking::create([
                    'tenant_id' => $user->id,
                    'owner_id' => $owner->id,
                    'parent_space_id'=>$space->id,
                    'status' => Booking::INPROCESS,
                ]);
                $booking->save();
                if(isset($request->message)&&$request->message!='') {
                    $requestMessage = new BookingMessage();
                    $requestMessage->booking_id = $booking->id;
                    $requestMessage->user_id = $user->id;
                    $requestMessage->message = $request->message;
                    $requestMessage->save();
                }
                $qty = 1;
                switch ($request->type){
                    case ('hourly'):
                        $request->request->set('date_end', $request->date_start);
                        if($request->hours_end==0) $request->request->set('hours_end', 24);
                        $count_hours = abs($request->hours_end - $request->hours_start);
                        if($count_hours<=0) $count_hours = 1;
                        $sum = $space->price_per_hour*$count_hours;
                        break;
                    case ('daily'):
                        $date1 = \DateTime::createFromFormat('Y-m-d',$request->date_start);
                        $date2 = \DateTime::createFromFormat('Y-m-d',$request->date_end);
                        $interval = $date1->diff($date2)->days;
                        $interval++;
                        $sum = $space->price_per_day*$interval;
                        break;
                    case ('monthly'):
                        $sum = $space->price_per_month;
                        if($request->number_months>1){
                            for($i=1; $i<$request->number_months; $i++){
                                $dateToPay = date('Y-m-d', strtotime("+$i months", strtotime($request->date_start)));
                                $dateToPay.=' 17:00:00';
                                $customquery->addDataToCustomQuery($sum.'-'.$booking->id,'MakeDelayedPayment',$dateToPay);
                            }
                        }
                        $request->request->set('date_end', date('Y-m-d', strtotime("+$request->number_months months", strtotime($request->date_start))));
                        $deposit = $space->capturePrice;
                        break;
                }
                $datedetails = $request->dateKey;
                $fee = $sum * $space->applicationFee / 100;
                $amount = round($sum + $fee + $deposit);

                $method = \Stripe\PaymentMethod::create([
                    'type' => 'card',
                    'card' => [
                        'number' => $request->cardNumber,
                        'exp_month' => $request->expMonth,
                        'exp_year' => $request->expYear,
                        'cvc' => $request->cvv,
                    ],
                ]);
                $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
                if($method) {
                    $stripe->paymentMethods->attach($method->id, ['customer' => $customerId]);

                    $payment_intent = \Stripe\PaymentIntent::create([
                        "payment_method_types" => ["card"],
                        'amount' => $amount,
                        'payment_method' => $method->id,
                        'customer' => $customerId,
                        'currency' => 'usd',
                        'transfer_data' => [
                            'amount' => round($sum),
                            'destination' => $owner->stripeAccountId,
                        ],
                        "confirmation_method" => "manual",
                        "capture_method" => "automatic",
                        'setup_future_usage' => 'off_session',
                    ]);
                }

                if (isset($customer) && !is_string($customer)) {
                    $user->stripeConnected = 1;
                    $user->stripeCustomerId = $customer->id;
                    $user->save();

                    $security_deposite = SecurityDeposite::create([
                        'booking_id'=>$booking->id,
                        'price'=>$deposit,
                        'status'=>Booking::PENDING,
                        'time_to_pay'=>$request->date_end,
                    ]);

                    $booking_item = BookingItem::create([
                        'booking_id'=>$booking->id,
                        'space_id'=>$space->id,
                        'space_name'=>$space->name,
                        'total_price'=>$sum,
                        'type_of_date'=>$request->type,
                        'qty'=>$qty,
                        'start_date'=>$request->date_start,
                        'end_date'=>$request->date_end,
                        'date_details'=>$datedetails,
                        'service_fee'=>$fee,
                        'status'=>Booking::PENDING,
                        'security_deposite_id'=>$security_deposite->id ?? 0,
                    ]);

                    $transaction = Transaction::create([
                        'stripe_id' => $payment_intent->id ?? null,
                        'booking_id' => $booking->id,
                        'booking_item_id' => $booking_item->id,
                        'total' => $sum,
                        'status' => 'draft'
                    ]);
                    $booking->status = Booking::PENDING;
                    $booking->save();

                    Mail::send(new HostNewBookingRequest($booking->id, $owner));

                    $customquery->addDataToCustomQuery($booking->id.'-18','HostApproveBookingRequest', Carbon::now()->addHours(6)->toDateTimeString());
                    $customquery->addDataToCustomQuery($booking->id.'-12','HostApproveBookingRequest', Carbon::now()->addHours(12)->toDateTimeString());
                    $customquery->addDataToCustomQuery($booking->id.'-6','HostApproveBookingRequest', Carbon::now()->addHours(18)->toDateTimeString());
                    $customquery->addDataToCustomQuery($booking->id,'AutoDeclineBookingRequest', Carbon::now()->addHours(24)->toDateTimeString());

                    return response()->json(
                        ['booking'=>$booking, 'user'=>$owner]
                    );
                } else {
                    return response()->json(['error' => $customer]);
                }

            } else {
                return response()->json('Token is expired!');
            }
            return response()->json(['booking'=>$booking, 'user'=>$owner] ,200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function requestCoworking(Request $request){
        try {
            $stripe = new StripeController;
            $customquery = new CustomQueryController();

            $user = auth('api')->user();
            if ($user) {
                $space = Space::where(['id' => $request->placeId])->first();
                $owner = $space->owner;

                $request->request->set('phone', $user->phoneNumber);
                $request->request->set('email', $user->email);

                $explodeExpireDate = explode('/', $request->expiryDate);

                $request->request->set('expMonth', $explodeExpireDate[0]);
                $request->request->set('expYear', $explodeExpireDate[1]);

                if (!$user->stripeCustomerId) {
                    $customer = $stripe->createCustomer($request);
                    $customerId = $customer->id;
                } else {
                    $customer = Customer::retrieve($user->stripeCustomerId);
                    $customerId = $user->stripeCustomerId;
                }

                $method = \Stripe\PaymentMethod::create([
                    'type' => 'card',
                    'card' => [
                        'number' => $request->cardNumber,
                        'exp_month' => $request->expMonth,
                        'exp_year' => $request->expYear,
                        'cvc' => $request->cvv,
                    ],
                ]);
                $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
                $stripe->paymentMethods->attach($method->id, ['customer' => $customerId]);

                $booking = Booking::create([
                    'tenant_id' => $user->id,
                    'owner_id' => $owner->id,
                    'parent_space_id'=>$space->id,
                    'status' => Booking::INPROCESS,
                ]);
                $booking->save();

                if (isset($request->message) && $request->message != '') {
                    $requestMessage = new BookingMessage();
                    $requestMessage->booking_id = $booking->id;
                    $requestMessage->user_id = $user->id;
                    $requestMessage->message = $request->message;
                    $requestMessage->save();
                }

                $total = 0;
                $deposit = 0;
                $finaldate_to_pay = '';
                $subscription_price = [];
                foreach ($request->cart as $cart_item) {
                    $security_deposite = (object)[];
                    $qty = 1;
                    $date = $cart_item['date'];
                    switch ($date['type']) {
                        case ('hourly'):
                            $startTime = $date['startTime'];
                            $endtime = $date['endTime'];
                            $date['endDate'] = $date['startDate'];
                            $startTime = substr_replace($startTime, ':', 2, 0);
                            $startTime = substr_replace($startTime, ' ', 5, 0);
                            $endtime = substr_replace($endtime, ':', 2, 0);
                            $endtime = substr_replace($endtime, ' ', 5, 0);
                            $startTime = date("H:i", strtotime($startTime));
                            $endtime = date("H:i", strtotime($endtime));
                            if($endtime=='00:00') $endtime = 24;
                            $count_hours = abs((int)$endtime - (int)$startTime);
                            if ($count_hours <= 0) $count_hours = 1;
                            if(isset($cart_item['qty'])&&$cart_item['qty'])
                                $qty = $cart_item['qty'];
                            $sum = $cart_item['price_per_hour'] * $count_hours * $qty;
                            $total += $sum;
                            break;
                        case ('daily'):
                            $date1 = \DateTime::createFromFormat('m/d/Y',$date['startDate']);
                            $date2 = \DateTime::createFromFormat('m/d/Y',$date['endDate']);
                            $interval = $date1->diff($date2)->days;
                            $interval++;
                            if(isset($cart_item['qty'])&&$cart_item['qty'])
                                $qty = $cart_item['qty'];
                            $sum = $cart_item['price_per_day'] * $interval * $qty;
                            $total += $sum;
                            break;
                        case ('monthly'):
                            $date['tempstartDate'] = \DateTime::createFromFormat('m/d/Y',$date['startDate']);
                            if(isset($cart_item['qty'])&&$cart_item['qty'])
                                $qty = $cart_item['qty'];
                            $sum = $cart_item['price_per_month'] * $qty;
                            $dateToPay = date("Y-m-d", strtotime("+1 month", $date['tempstartDate']->getTimestamp()));
                            $dateToPay.=' 17:00:00';
                            if($cart_item['kind']=='hot desk' || $cart_item['kind']=='membership passes') {
                                $subscription_price[] = ['space'=>$cart_item,'sum'=>$sum,'date'=>$date];
                            } else {
                                for ($i = 1; $i < $request->number_months; $i++) {
                                    $month_to_add = "+$i month";
                                    $dateToPay = date("Y-m-d", strtotime($month_to_add, $date['tempstartDate']->getTimestamp()));
                                    $dateToPay .= ' 17:00:00';
                                    $customquery->addDataToCustomQuery("$sum - $booking->id", 'MakeDelayedPayment', $dateToPay);
                                }
                            }
                            $total += $sum;
                            $deposit += $cart_item['capturePrice'];
                            break;
                    }
                    if(isset($dateToPay) && strtotime($dateToPay)>strtotime($finaldate_to_pay)) $finaldate_to_pay = $dateToPay;
                    $datedetails = $date['dateKey'];
                    $fee = $sum * $space->applicationFee / 100;
                    $amount = $total + $fee + $deposit;

                    $booking_item = BookingItem::create([
                        'booking_id' => $booking->id,
                        'space_id' => $cart_item['id'],
                        'space_name' => $cart_item['name'],
                        'total_price' => round($sum), //fix
                        'qty' => $qty,
                        'start_date' => \DateTime::createFromFormat('m/d/Y',$date['startDate']),
                        'end_date' => \DateTime::createFromFormat('m/d/Y',$date['endDate']),
                        'date_details' => $datedetails,
                        'service_fee' => $fee,
                        'type_of_date' => $date['type'],
                        'status' => Booking::PENDING,
                        'security_deposite_id' => $security_deposite->id ?? 0,
                    ]);
                }

                if($finaldate_to_pay)
                    $security_deposite = SecurityDeposite::create([
                        'booking_id' => $booking->id,
                        'price' => $deposit,
                        'status' => Booking::PENDING,
                        'time_to_pay' => $finaldate_to_pay,
                    ]);
                if($subscription_price) {
                    $customquery = new CustomQueryController();
                    foreach($subscription_price as $item){
                        $dateToPay = date("Y-m-d", strtotime("+1 month -1 day", $item['date']['tempstartDate']->getTimestamp()));
                        $dateToPay.=' 17:00:00';
                        $customquery->addDataToCustomQuery($item['sum'].'-'.$item['space']['id'].'-'.$booking->id, 'Subscription', $dateToPay);
                    }
                }

                $payment_intent_array = [
                    "payment_method_types" => ["card"],
                    'amount' => round($amount),
                    'payment_method' => $method->id,
                    'customer' => $customerId,
                    'currency' => 'usd',
                    "confirmation_method" => "manual",
                    "capture_method" => "automatic",
                    'setup_future_usage' => 'off_session',
                ];

                if($total>0){
                    $payment_intent_array['transfer_data'] = [
                        'amount' => round($total),
                        'destination' => $owner->stripeAccountId,
                    ];
                }

                $payment_intent = \Stripe\PaymentIntent::create($payment_intent_array);

                if (isset($customer) && !is_string($customer)) {
                    $user->stripeConnected = 1;
                    $user->stripeCustomerId = $customer->id;
                    $user->save();
                }

                $transaction = Transaction::create([
                    'stripe_id' => $payment_intent->id,
                    'booking_id' => $booking->id,
                    'booking_item_id' => 0,
                    'total' => $total,
                    'status' => 'draft'
                ]);
                $booking->status = Booking::PENDING;
                $booking->save();

                Mail::send(new HostNewBookingRequest($booking->id, $owner,'coworking'));
                $customquery = new CustomQueryController();
                $customquery->addDataToCustomQuery($booking->id . '-18', 'HostApproveBookingRequest', Carbon::now()->addHours(6)->toDateTimeString());
                $customquery->addDataToCustomQuery($booking->id . '-12', 'HostApproveBookingRequest', Carbon::now()->addHours(12)->toDateTimeString());
                $customquery->addDataToCustomQuery($booking->id . '-6', 'HostApproveBookingRequest', Carbon::now()->addHours(18)->toDateTimeString());
                $customquery->addDataToCustomQuery($booking->id, 'AutoDeclineBookingRequest', Carbon::now()->addHours(24)->toDateTimeString());

                return response()->json(
                    ['booking' => $booking, 'user' => $owner, 'subs'=>$subscription_price]
                );
            }
            else {
                return response()->json('Token is expired!');
            }
            return response()->json(['booking'=>$booking, 'user'=>$owner] ,200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function editrequest(Request $request){
        try{
            $stripe = new StripeController;

            $user = auth('api')->user();
            if ($user) {
                $space = Space::where(['id' => $request->placeId])->first();
                $owner = $space->owner;
                $bookingRequest = Requests::where('id', $request->reqId)->first();
                $bookingRequest->status = Requests::PENDING;
                $bookingRequest->save();

                $request->request->set('phone', $user->phoneNumber);
                $request->request->set('email', $user->email);
                $explodeExpireDate = explode('/', $request->expiryDate);
                $request->request->set('expMonth', $explodeExpireDate[0]);
                $request->request->set('expYear', $explodeExpireDate[1]);
                if (!$user->stripeCustomerId) {
                    $customer = $stripe->createCustomer($request);
                    $customerId = $customer->id;
                } else {
                    $customer = Customer::retrieve($user->stripeCustomerId);
                    $customerId = $user->stripeCustomerId;
                }

                $space = Space::where('id',$request->placeId)->first();
                $activityPrice = ActivitySpace::where('space_id',$space->id)->where('activity_id',$request->activityId)->first();
                $sum = $activityPrice->value*$request->quantity;
                $fee = $sum * $space->applicationFee / 100;
                $deposit = $space->capturePrice;

                $method = \Stripe\PaymentMethod::create([
                    'type' => 'card',
                    'card' => [
                        'number' => $request->cardNumber,
                        'exp_month' => $request->expMonth,
                        'exp_year' => $request->expYear,
                        'cvc' => $request->cvc,
                    ],
                ]);
                $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET'));
                $stripe->paymentMethods->attach($method->id,
                    ['customer'=>$customerId]);
                $payment_intent = \Stripe\PaymentIntent::create([
                    "payment_method_types" => ["card"],
                    'amount' => $request->amount,
                    'payment_method' => $method->id,
                    'customer' => $customerId,
                    'currency' => 'usd',
                    'transfer_data' => [
                        'amount'=>round($sum),
                        'destination' => $owner->stripeAccountId,
                    ],
                    "confirmation_method" => "manual",
                    "capture_method" => "automatic",
                    'setup_future_usage' => 'off_session',
                ]);

                if (isset($customer) && !is_string($customer)) {
                    $user->stripeConnected = 1;
                    $user->stripeCustomerId = $customer->id;
                    $user->save();

                    if (isset($request->message) && $request->message != '') {
                        $requestMessage = new RequestMessages();
                        $requestMessage->request_id = $bookingRequest->id;
                        $requestMessage->user_id = $user->id;
                        $requestMessage->message = $request->message;
                        $requestMessage->save();
                    }

                    $transaction = Transaction::create([
                        'stripe_invoice_id' => $payment_intent->id,
                        'total' => $request->amount,
                        'amount'=>round($sum),
                        'status' => 'draft'
                    ]);

                    //cancel or refund old transaction

                    $booking = Booking::where('request_id', $bookingRequest->id)->first();
                    $oldBooking = Booking::where('request_id', $bookingRequest->id)->first();
                    $booking->start_day = Carbon::parse($request->range[0]);
                    $booking->end_day = Carbon::parse($request->range[1]);
                    $booking->transaction_id = $transaction->id;
                    $booking->activity_id = $request->activityId;
                    $booking->person_count = $request->personCount;
                    $booking->status = Booking::PENDING;
                    $booking->price_per_day = $activityPrice->value;
                    $booking->capture_price = $space->capturePrice;
                    $booking->service_fee = $space->applicationFee;
                    $booking->save();
                    Mail::send(new HostUpdateBookingRequest($booking, $oldBooking, $owner));
                    Mail::send(new TenantUpdateBookingRequest($booking, $oldBooking, $user));
                }

                $customquery = new CustomQueryController();
                $customquery->deleteDataFromCustomQueryByData($booking->id);
                $customquery->addDataToCustomQuery($booking->id.'-18', 'HostApproveBookingRequest', Carbon::now()->addHours(6)->toDateTimeString());
                $customquery->addDataToCustomQuery($booking->id.'-12', 'HostApproveBookingRequest', Carbon::now()->addHours(12)->toDateTimeString());
                $customquery->addDataToCustomQuery($booking->id.'-6', 'HostApproveBookingRequest', Carbon::now()->addHours(18)->toDateTimeString());
                $customquery->addDataToCustomQuery($booking->id, 'AutoDeclineBookingRequest', Carbon::now()->addHours(24)->toDateTimeString());

                return response()->json(
                    ['booking' => $booking, 'user' => $owner]
                );
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function getrequest($id){
        try {
            $user = auth('api')->user();
            $booking = Booking::where('id', $id)->where('tenant_id', $user->id)->first();
            return response()->json([$booking,$id,$user]);
        }
        catch (\Exception $e) {
                return response()->json([
                    'status' => 'error',
                    'error' => $e->getMessage()
                ], 404);
            }
    }

    public function createCheckoutSession(Request $request) {
        Stripe::setApiKey(env('STRIPE_SECRET'));

        $checkout_session = \Stripe\Checkout\Session::create([
            'success_url' => url('/success?session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => url('/canceled'),
            'payment_method_types' => ['card'],
            'mode' => 'payment',
            'line_items' => [[
                'name' => 'TEST',
                'quantity' => $request->get('quantity'),
                'currency' => 'USD',
                'amount' => $request->get('amount')
            ]]
        ]);

        return response()->json(['sessionId' => $checkout_session->id]);
    }

    public function approve(Booking $booking) {
        Stripe::setApiKey(env('STRIPE_SECRET'));

        $user = auth('api')->user();
        if($user) {
            $booking->status = Booking::CONFIRMED;
            $bookingsItems = BookingItem::where('booking_id',$booking->id)->get();
            foreach($bookingsItems as $item){
                $item->status = Booking::CONFIRMED;
                $item->save();
            }
            $booking->save();
            $bookings_to_deny = Booking::where('parent_space_id',$booking->parent_space_id)->where('status',Booking::PENDING)->where('id','<>',$booking->id)->get();

            $custom_query = new CustomQueryController();
            $custom_query->deleteDataFromCustomQueryByData($booking->id);
            $custom_query->addDataToCustomQuery($booking->id,'SendMoneyToHost', Carbon::createFromFormat('Y-m-d H:i:s',$booking->items()->orderBy('start_date','asc')->first()->start_date)->subDay());


            $response = [
                'status' => 'success',
            ];
            return $response;
        } else return response()->json(
                ['message' => 'Non auth']
                , 400);
        }

    public function decline(Booking $booking) {
        Stripe::setApiKey(env('STRIPE_SECRET'));
        $user = auth('api')->user();
        if($user) {
            $transaction = Transaction::where('booking_id',$booking->id)->first();
            $payment = PaymentIntent::retrieve($transaction->stripe_id);
            if($payment->status == 'succeeded') {
                Refund::create([
                    'payment_intent' => $transaction->stripe_id
                ]);
            } else {
                $payment->cancel();
                $transaction->status='canceled';
                $transaction->save();
            }
            $booking->status=Booking::DECLINED;
            foreach($booking->items as $item){
                $item->status = Booking::DECLINED;
                $item->save();
            }
            $booking->save();
            $custom_query = new CustomQueryController();
            $custom_query->deleteAllDataFromCustomQueryByData($booking->id);
            $response = [
                'status' => 'success',
            ];
            return $response;
        } else return response()->json(
            ['message' => 'Not auth']
            , 400);

        return $response;
    }

    public function cancel(Booking $booking) {
        Stripe::setApiKey(env('STRIPE_SECRET'));
        $user = auth('api')->user();
        if($user) {
            $transaction = Transaction::where('booking_id',$booking->id)->first();
            $payment = PaymentIntent::retrieve($transaction->stripe_id);
            if($payment->cancel()) {
                $booking->status=Booking::CANCELED;
                foreach($booking->items as $item){
                    $item->status = Booking::CANCELED;
                    $item->save();
                }
                $booking->save();
                $transaction->status='canceled';
                $transaction->save();
                $custom_query = new CustomQueryController();
                $custom_query->deleteAllDataFromCustomQueryByData($booking->id);
                $type = $booking->space->parent_id ? 'coworking' : 'independent';
                if($user->role_id!=2) Mail::send(new HostGuestCancelsBooking($booking->id, $booking->owner,$type));
                $response = [
                    'status' => 'success',
                ];
                return $response;
            } else return response()->json(
                ['message' => 'Stripe error']
                , 400);
        } else return response()->json(
            ['message' => 'Not auth']
            , 400);
    }

    public function refund($booking) {
        Stripe::setApiKey(env('STRIPE_SECRET'));
        $user = auth('api')->user();
        if($user) {
            $transaction = Transaction::where('booking_id',$booking->id)->first();
            $payment = PaymentIntent::retrieve($transaction->stripe_id);
            if ($payment->cancel()) {
                $booking->status=Booking::CANCELED;
                foreach($booking->items as $item){
                    $item->status = Booking::CANCELED;
                    $item->save();
                }
                $booking->save();
                $transaction->status='canceled';
                $transaction->save();
                $custom_query = new CustomQueryController();
                $custom_query->deleteAllDataFromCustomQueryByData($booking->id);

                $response = [
                    'status' => 'success',
                ];
                return $response;
                } else return response()->json(
                    ['message' => 'Stripe error']
                    , 400);
            } else return response()->json(
                ['message' => 'Not auth']
                , 400);
    }

    public function releasedeposit($id){
        Stripe::setApiKey(env('STRIPE_SECRET'));

        $booking = Booking::where(['id' => $id])->with(['transaction','security_dep','items'])->first();

        $transaction = $booking->transaction;
        if($booking->security_dep && $booking->security_dep->status=='pending' && $transaction && $transaction->stripe_invoice_id) {
            if ($booking->security_dep->price>0) {
                Refund::create([
                    'payment_intent' => $transaction->stripe_invoice_id,
                    'amount' => $booking->security_dep->price,
                ]);
            }

            $user = auth('api')->user();
            if($user) {
                $booking->status = Booking::COMPLETED;
                $booking->transaction->status = 'deposit sent';
                $booking->transaction->save();
                foreach($booking->items as $item){
                    $item->status = Booking::COMPLETED;
                    $item->save();
                }
                $booking->save();
                $type = 'independent';
                if(Space::where('parent_id',$booking->space->id)->count()){
                    $type = 'coworking';
                }
                Mail::send(new HostSpaceBookingCompleted($booking->id, $booking->space->owner, $type));
                $response = [
                    'status' => 'success',
                ];
                return $response;
            } else return response()->json(
                ['message' => 'User auth failed']
                , 400);
        }
    }

    public function cancelSubscription(Request $request){
        $bookingId = $request->get('booking');
        $spaceId = $request->get('space');
        CustomQuery::where('data','like','%-'.$spaceId.'-'.$bookingId)->delete();
        return response()->json(['status' => 'success'], 200);
    }
}

?Ю