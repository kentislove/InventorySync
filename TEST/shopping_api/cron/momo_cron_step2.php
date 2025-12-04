<?php
/**
 * MOMO 同步排程
 * 扣除Yahoo庫存
 */

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

use App\Libraries\FetchHttp;


$startTime = time();
echo "開始: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB<br>\r\n";
demo();
echo "結束: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB, 時間: " .(time() - $startTime). "<br>\r\n";
function demo()
{
    $momoShopping = new MomoShopping;
    $momoLoginInfo = $momoShopping->loginInfo;


    $myMomo = new MyMomo;

    $yahooShopping = new YahooShopping;
    $yahooShopping->login();

    $myYahoo = new myYahoo;

    $fetch = new FetchHttp;


    $strSQL = "SELECT * FROM orders WHERE third_channel = :third_channel AND is_sync = :is_sync AND my_status = :my_status ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute([
        'third_channel' => 'momo',
        'is_sync' => 'n',
        'my_status' => 'new',
    ]);
    while ($order = $sth->fetch()) {
        $dt = date('Y-m-d H:i:s');
        $opQty = $order['qty'];

        // 線上Y庫存扣
        if (! $order['y_id']) {
            $txt = "MOMO訂單#".$order['third_no']." ".$order['part'].'+'.$order['attr2']." 配對不到 Yahoo 商品";
            addOpLog(['message' => $txt]);
            continue;
        }
        $yStock = $yahooShopping->Stock_GetQty($order['y_id']);
        $updQty = $yStock - $order['qty'];
        $updQty = $updQty < 0 ? 0 : $updQty;
        //
        if (app_env == 'production') {
            $updates = [];
            $updates['ProductId'] = $order['y_id'];
            $updates['Qty'] = $opQty * -1;
            $res = $yahooShopping->Stock_UpdateQty($updates);
            $yStock = $yahooShopping->Stock_GetQty($order['y_id']);
        }
        //
        $upds = [];
        $upds['local_stock'] = $yStock;
        $upds['y_stock'] = $yStock;
        $upds['m_last_order_id'] = $order['id'];
        $upds['m_last_order_no'] = $order['third_no'];
        $upds['m_last_order_date'] = $order['third_create_date'];
        $where = [];
        $where['y_id'] = $order['y_id'];
        $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
        $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth2->execute($tmp['params']);
        //
        $txt = "MOMO: 訂單 ".$order['third_no']." ".$order['part'].'+'.$order['attr2']." Y庫存-".$opQty."=" .$yStock;
        addOpLog(['message' => $txt]);
        //
        $upds = [];
        $upds['is_sync'] = 'y';
        $upds['updated_at'] = $dt;
        $where = [];
        $where['id'] = $order['id'];
        $tmp = pdo_update_sql('orders', $upds, false, false, $where);
        $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth2->execute($tmp['params']);
    }
    $txt = "MOMO 同步庫存 End";
    addOpLog(['message' => $txt]);
}
