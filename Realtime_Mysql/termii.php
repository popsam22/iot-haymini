<?php

function sendSms($message, $phone){
  $curl = curl_init();

  $BASE_URL = "https://v3.api.termii.com/api/sms/send";

  $data = [
    // "to" => "2349134327450",
    "to" => $phone,
    "from" => "Haymini",
    "type" => "plain",
    "channel" => "generic",
    "api_key" => "TLafynYzGqTyuduvHNjGTqfeYEsIXKChiyJSBmgLnCQfkTzCTjsQIiuSqLNFnO",
    "sms" => "Hi there, your child clocked in at $message"
  ];

  $post_data = json_encode($data);

  curl_setopt_array($curl, array(
  CURLOPT_URL => $BASE_URL,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS => $post_data,
  CURLOPT_HTTPHEADER => array(
    "Content-Type: application/json"
  ),
  ));

  $response = curl_exec($curl);
  if (curl_errno($curl)) {
        echo "cURL Error: " . curl_error($curl);
    }

  curl_close($curl);
  var_dump($response);
  echo $response;
}
?>




TLafynYzGqTyuduvHNjGTqfeYEsIXKChiyJSBmgLnCQfkTzCTjsQIiuSqLNFnO 
SENDER ID = Haymini