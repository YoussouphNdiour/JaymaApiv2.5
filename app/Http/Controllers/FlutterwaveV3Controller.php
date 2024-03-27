<?php

namespace App\Http\Controllers;


use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\PaymentRequest;
use App\Traits\Processor;
use Illuminate\Support\Facades\Http;
use BaconQrCode\Encoder\QrCode;
use BaconQrCode\Writer\ImageWriter;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class FlutterwaveV3Controller extends Controller
{
    use Processor;

    private $config_values;
    private PaymentRequest $payment;
    private $user;

    public function __construct(PaymentRequest $payment, User $user)
    {
        $config = $this->payment_config('flutterwave', 'payment_config');
        if (!is_null($config) && $config->mode == 'live') {
            $this->config_values = json_decode($config->live_values);
        } elseif (!is_null($config) && $config->mode == 'test') {
            $this->config_values = json_decode($config->test_values);
        }
        $this->payment = $payment;
        $this->user = $user;
    }
    private function getAccessToken()
    {
        $url = "https://api.orange-sonatel.com/oauth/token";
        $data = [
            "client_id" => "6169a61e-d6bb-48be-b899-0f9dbfe78b05",
            "client_secret" => "3e5833e0-3151-449a-800c-4a322d7d268b",
            "grant_type" => "client_credentials",
        ];

        {
        $hearders_token = array(
            'Content-Type' =>'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        );
        try {
            $response = Http::asForm()->post($url, $data);
            $token = $response->json()["access_token"];
            return $token;
        } catch (\Throwable $th) {
            return null;
        }
    }
    }
    public function initialize(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|uuid'
        ]);

        if ($validator->fails()) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_400, null, $this->error_processor($validator)), 400);
        }

        $data = $this->payment::where(['id' => $request['payment_id']])->where(['is_paid' => 0])->first();
        if (!isset($data)) {
            return response()->json($this->response_formatter(GATEWAYS_DEFAULT_204), 200);
        }

        if ($data['additional_data'] != null) {
            $business = json_decode($data['additional_data']);
            $business_name = $business->business_name ?? "my_business";
        } else {
            $business_name = "my_business";
        }
       // $payer = json_decode($data['payer_information']);

        //* Prepare our rave request
         $token = $this->getAccessToken();

        if($token != null){
            try {
                $headers = array(
                    "Authorization" => "Bearer ".$token,  // access token from login
                   // 'Content-Type' =>'application/json',
                     'Accept' => 'application/json',
                );
    
                $paylod = array(
                    "amount" => array(
                        "unit" => "XOF",
                        "value" => $data->payment_amount
                    ),
                    "callbackCancelUrl" => "https://apishop.jaymagadegui.sn/payment-fail",
                    "callbackSuccessUrl" => route('flutterwave-v3.callback', ['payment_id' => $data->id]),
                    "code" => 520309,
                    "name"=> "Jayma Gade Gui",
                    "validity"=> 1500
                );
    
                // echo json_encode($data, JSON_PRETTY_PRINT);
                $response = Http::asJson()->withToken($token)->post('https://api.orange-sonatel.com/api/eWallet/v4/qrcode', $paylod);
                $link = $response->json()["deepLinks"];
                $linkmaxit = $link['MAXIT'];
                $linkqrId = $response->json()['qrCode'];
                // $data->payment_platform;
                if($data->payment_platform == "web"){
                    //je veux afficher le qrcode sur une vue jai deja fait la transfromation
                    //return $qrCodeImage;
                    return '<!DOCTYPE html>
<html lang="en">
<head>
  <title>Bootstrap 5 Example</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="20;url='. $data->external_redirect_link .'">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>

<div class="container-fluid p-5 bg-primary text-white text-center">
  <h1>Paiement avec Orange money</h1>
    <img src="https://apishop.jaymagadegui.sn/storage/app/public/business/2024-02-26-65dc56cec59bf.png" style="max-width:25%; height:auto;" />
</div>
  
<div class="container mt-5 ">
  <div class="row ">
  
     <div class="col-sm">
     .
  </div>
    <div class="col-auto justify-content-center">
      <h3>Scannez le Code QR</h3>
       <img style="min-width:50%;height:auto;" src="data:image/png;base64,'.$linkqrId.'"/> 
      
    </div>
     <div class="col-sm">
   .
  </div>

    </div>
  </div>
</div>

</body>
</html>';
                    //'<img style="max-width:100%;height:auto;" src="data:image/png;base64,'.$linkqrId.'"/>       ';
                   
                }
                else{
                    // c'est le button maxit si la platform est mobile
                 return  redirect()->away($linkmaxit);
                }
            } catch(\Throwable $e) {
                return $e;
            }
        }else{
            return 'Token is null';
        }
    }

    public function callback(Request $request)
    {

               // if ($amountPaid >= $amountToPay) {
        $txid = $request['payment_id'];
        $this->payment::where(['id' => $request['payment_id']])->update([
            'payment_method' => 'Orange Money',
            'is_paid' => 1,
            'transaction_id' => $txid,
        ]);

        $data = $this->payment::where(['id' => $request['payment_id']])->first();

        if (isset($data) && function_exists($data->success_hook)) {
            call_user_func($data->success_hook, $data);
        }
        return $this->payment_response($data,'success');
              //  }
        // if ($request['status'] == 'successful' || $request['status'] == 'completed' ) {
        //     $txid = $request['transaction_id'];
        //     $curl = curl_init();
        //     curl_setopt_array($curl, array(
        //         CURLOPT_URL => "https://api.flutterwave.com/v3/transactions/{$txid}/verify",
        //         CURLOPT_RETURNTRANSFER => true,
        //         CURLOPT_ENCODING => "",
        //         CURLOPT_MAXREDIRS => 10,
        //         CURLOPT_TIMEOUT => 0,
        //         CURLOPT_FOLLOWLOCATION => true,
        //         CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        //         CURLOPT_CUSTOMREQUEST => "GET",
        //         CURLOPT_HTTPHEADER => array(
        //             "Content-Type: application/json",
        //             "Authorization: Bearer " . $this->config_values->secret_key,
        //         ),
        //     ));
        //     $response = curl_exec($curl);
        //     curl_close($curl);

        //     $res = json_decode($response);
        //     if ($res->status) {
        //         $amountPaid = $res->data->charged_amount;
        //         $amountToPay = $res->data->meta->price;
        //         if ($amountPaid >= $amountToPay) {

        //             $this->payment::where(['id' => $request['payment_id']])->update([
        //                 'payment_method' => 'flutterwave',
        //                 'is_paid' => 1,
        //                 'transaction_id' => $txid,
        //             ]);

        //             $data = $this->payment::where(['id' => $request['payment_id']])->first();

        //             if (isset($data) && function_exists($data->success_hook)) {
        //                 call_user_func($data->success_hook, $data);
        //             }
        //             return $this->payment_response($data,'success');
        //         }
        //     }
        // }
        // $payment_data = $this->payment::where(['id' => $request['payment_id']])->first();
        // if (isset($payment_data) && function_exists($payment_data->failure_hook)) {
        //     call_user_func($payment_data->failure_hook, $payment_data);
        // }
        // return $this->payment_response($payment_data,'fail');
    }
}
