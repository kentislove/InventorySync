<?php
/**
 * Yahoo 排程，變更已出貨
 * 
 * Yahoo 已出貨訂單
 * https://tw.supplier.yahoo.com/docs/scm/api/actions/storedelivery/getshippingorders/
 */

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

use App\Libraries\FetchHttp;


$startTime = time();
// 已出貨訂單（變更訂單狀態）
echo "開始: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB<br>\r\n";
Yahoo_ShippingOrders();
echo "結束: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB, 時間: " .(time() - $startTime). "<br>\r\n";
return ;
function Yahoo_ShippingOrders()
{
    $momoShopping = new MomoShopping;

    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    
    $myYahoo = new MyYahoo;

    $params = [];
    $params['start_date'] = date('Y-m-d', strtotime('-5 day')).'T00:00:00';
    $params['end_date'] = date('Y-m-d').'T23:59:59';

    $output = $yahooShopping->getShippingOrders($params);
    $totalOrders = $output['TotalOrders'];
    //
    foreach ($totalOrders as $shipKey => $orders) {
        foreach ($orders as $i => $v) {
            $dt = date('Y-m-d H:i:s');
            //
            unset($v['ReceiverInfo']);
            unset($v['BuyerInfo']);
            // 訂單
            $orderNo = $v['OrderInfo']['OrderCode'];
            $orderDate = $v['OrderInfo']['TransferDate']; // Y-m-dTH:i:s
            //
            $info = $v['Products'][0];
            $yId = $info['Id'];
            $part = $info['SupplierNo'];
            $yName = $info['Name'];
            $yAttr = $info['Attribute'];
            $qty = $info['Qty'];
            //
            $strSQL = "SELECT * FROM orders WHERE third_channel = 'yahoo' AND my_status = 'new' AND third_no = :third_no ";
            $sth = $GLOBALS['dbh']->prepare($strSQL);
            $sth->execute([
                'third_no' => $orderNo,
            ]);
            $order = $sth->fetch();
            if ($order) {
                // 更新
                $upds = [];
                $upds['third_status'] = '已出貨';
                $upds['my_status'] = 'shipped';
                $upds['is_sync'] = 'x';
                $upds['updated_at'] = $dt;
                $where = [];
                $where['id'] = $order['id'];
                $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
                $sth2->execute($tmp['params']);
                addOpLog(['message' => "Yahoo: 訂單 ".$order['third_no'].' 已出貨']);
            }
        }
    }
    addOpLog(['message' => "Yahoo 檢查已出貨 End"]);
}