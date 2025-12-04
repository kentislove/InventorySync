<?php

$get = $_GET ?? [];
$post = $_POST ?? [];
$input = file_get_contents('php://input');

file_put_contents('tmp/yahoo_notify'.date('d').'.txt', $input);

if (! empty($input)) {
    $data = json_decode($input, true);
    if (! empty($data['FileList'])) {
        foreach ($data['FileList'] as $i => $filePath) {
            $res = file_get_contents($filePath);
            $destPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/tmp/yahoo_notify-'.$i.'.csv';
            file_put_contents($destPath, $res);
        }
    }
}

// file_put_contents('tmp/yahoo_notify'.date('d').'.txt', "GET:\n", FILE_APPEND);
// file_put_contents('tmp/yahoo_notify'.date('d').'.txt', json_encode($get)."\n", FILE_APPEND);
// file_put_contents('tmp/yahoo_notify'.date('d').'.txt', "POST:\n", FILE_APPEND);
// file_put_contents('tmp/yahoo_notify'.date('d').'.txt', json_encode($post)."\n", FILE_APPEND);

echo '1|'.time();