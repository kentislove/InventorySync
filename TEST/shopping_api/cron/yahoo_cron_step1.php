<?php
/**
 * Yahoo 待出貨訂單
 * 不留暫存資料，直接扣各平台庫存
 */

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

// 待出貨訂單
$startTime = time();
echo "Yahoo 開始: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB ".PHP_EOL;
Yahoo_GetPreparingOrders();
echo "結束: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB, 時間: " .(time() - $startTime). PHP_EOL;
function Yahoo_GetPreparingOrders()
{
    $momoShopping = new MomoShopping;
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    $myYahoo = new MyYahoo;
    //
    $startTime = strtotime('-4 hour');
    $startDate = date('Y-m-d', $startTime).'T'.date('H:00:00', $startTime);
    //
    $params = [];
    $params['start_date'] = $startDate;
    $params['end_date'] = date('Y-m-d').'T23:59:59';
    //
    $output = $yahooShopping->getPreparingOrders($params);
    $totalOrders = $output['TotalOrders'] ?? [];
    foreach ($totalOrders as $shipKey => $orders) {
        foreach ($orders as $i => $v) {
            $dt = date('Y-m-d H:i:s');
            //
            unset($v['ReceiverInfo']);
            unset($v['BuyerInfo']);
            // 訂單
            $orderNo = $v['OrderInfo']['OrderCode'];
            $orderDate = $v['OrderInfo']['TransferDate']; // Y-m-dTH:i:s
            $thirdOrderDate = str_replace('T', ' ', $orderDate);
            // 
            self_addYahooOrderLog($orderNo, $v);
            // 商品
            $products = $v['Products'];
            // 暫定一單只有一個商品
            $yId = $products[0]['Id'];
            $name = $products[0]['Name'];
            $part = $products[0]['SupplierNo'];
            $qty = $products[0]['Qty'];
            //
            $strSQL = "SELECT * FROM orders WHERE third_channel = :third_channel AND third_no = :third_no ";
            $sth = $GLOBALS['dbh']->prepare($strSQL);
            $sth->execute([
                'third_channel' => 'yahoo',
                'third_no' => $orderNo,
            ]);
            $order = $sth->fetch();
            if (! $order) {
                $product = $myYahoo->getById($yahooShopping, $yId);
                if ($product) {
                    $adds = [];
                    $adds['third_channel'] = 'yahoo';
                    $adds['third_sub'] = $shipKey;
                    $adds['third_no'] = $orderNo;
                    $adds['third_create_date'] = $thirdOrderDate;
                    $adds['part'] = $part;
                    $adds['match_str'] = $product['_match_str'];
                    $adds['attr1'] = $product['_attr1'];
                    $adds['attr2'] = $product['_attr2'];
                    $adds['name'] = $name;
                    $adds['qty'] = $qty;
                    $adds['y_id'] = $yId;
                    $adds['created_at'] = $dt;
                    $adds['updated_at'] = $dt;
                    $tmp = pdo_insert_sql('orders', $adds);
                    $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth2->execute($tmp['params']);
                    $rowCount = $sth2->rowCount();
                    if ($rowCount) {
                        addOpLog(['message' => 'Yahoo 新訂單 '.$orderNo]);
                        $lastOrderId = $GLOBALS['dbh']->lastInsertId();
                    }
                }
            }
        }
    }
}

function self_addYahooOrderLog($orderNo, $v)
{
    $dirPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/log/y_order/'.date('y');
    if (! is_dir($dirPath)) {
        mkdir($dirPath);
    }
    $logPath = $dirPath.'/'.$orderNo.'.json';
    file_put_contents($logPath, json_encode($v, JSON_UNESCAPED_UNICODE));
}