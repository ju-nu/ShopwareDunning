<?php

$curl = curl_init();

curl_setopt_array($curl, [
  CURLOPT_URL => "https://backend.kraeuterland.ltd/api/_action/document/01975dc834887067a028e0da20a22869/9D9HqcQL170b4H9RvftYMqW2mIAsYRge",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 30,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "GET",
  CURLOPT_POSTFIELDS => "",
  CURLOPT_HTTPHEADER => [
    "Accept: application/json",
    "Authorization: eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiJTV0lBVU5KSVJFNFpSM0hIVkRWMVNNNUNERyIsImp0aSI6Ijk5YzRiZWQzZmRiN2JhZDdjNTExYTQ3YjliN2Q5Y2Y3OTcyZjQ3ZGY5MWE2MTU0YzA5ZjkyODU0Y2Q5ZDhhNGIyMDU4NzZkZDhlMmNiOWRkIiwiaWF0IjoxNzUxOTY5NzI4LjkwMjYwMiwibmJmIjoxNzUxOTY5NzI4LjkwMjYwMywiZXhwIjoxNzUxOTcwMzI4LjkwMjAzNSwic3ViIjoiIiwic2NvcGVzIjpbIndyaXRlIl19.Qup1hR-79nOP6wWUBJd0WNUKYdosDAXZayN7P985Cq6K59Tiutwh3usYUUtZ5GTeKgtxmVH2LfUnTIP1eSMi-R9aN2JB595jbMQDAmIUPXOeFRIrTA-Bm6Ty8hDKAT5Yc1cUXaPo_tPAM8ECkJODWUM3wxu3YYZDir08joKQq9CqqXg9B8uAoR5UNtUiQuC33fCK2NAOx4Ps2neOz7qeCCobQnfeVn8zTimJSCWzv2H_CHF9eWWqCH6eOP5dAUNz73qc6shPsKtQynvMkeOsdciSx0H_x8akpWy5pPoUjGynbfjkbLaENXzhW6__TN002q6ulAnNnTVQPWqAr5KZRQ SWIAUNJIRE4ZR3HHVDV1SM5CDG",
    "Content-Type: application/json"
  ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
  echo "cURL Error #:" . $err;
} else {
  echo $response;
}