<?php
/**
 * PCHome 同步排程
 * 扣除其他庫存
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
    
    $myMomo = new MyMomo;
    
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    
    $myYahoo = new myYahoo;

    $pchomeShopping = new PCHomeShopping;

    $fetch = new FetchHttp;


    $strSQL = "SELECT * FROM orders WHERE third_channel = 'pchome' ";
    $strSQL .= "AND is_sync IN ('n', 'xx') AND my_status = 'new' ";
    $strSQL .= "LIMIT 0, 10 ";
    // $strSQL .= 'AND third_no = "20251127595047-01" '; // 測試用
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute();
    while ($order = $sth->fetch()) {
        
        $dt = date('Y-m-d H:i:s');
        $pId = $order['p_id'];
        $orderNo = $order['third_no'];
        $opQty = $order['qty'];
        //
        $tmp = $pchomeShopping->getOrderInfo([$orderNo]);
        $remoteOrder = $tmp[0] ?? [];
        if (! $remoteOrder) {
            echo "訂單不存在<br>\r\n";
            continue;
        }

        $third_status = $remoteOrder['ShipCheckStatus'];
        $my_status = '';
        //
        switch ($third_status) {
            case 'Shipped':
                $my_status = 'shipped';
                break;
            case 'Checking':
                $my_status = 'shipped';
                break;
            case 'Error':
                break;
            case '': // 
                $my_status = 'new';
                break;
        }
        // 是否不出貨 [1:不出貨] [0:出貨]
        if ($remoteOrder['isNotShip'] == 1) {
            $my_status = 'cancel';
        }
        // 已退貨，等待退款
        if ($remoteOrder['CancelQty'] == $remoteOrder['OrderQty']) {
            $my_status = 'cancel';
        }
        if (! $my_status) {
            echo "訂單狀態錯誤 " .$order['third_no']. "<br>\r\n";
            // echo "<pre>" .print_r($remoteOrder, true). "</pre>";
            continue;
        }

        if ($my_status == 'cancel') {
            // 取消單
            // 更新
            $upds = [];
            $upds['third_status'] = $third_status;
            $upds['my_status'] = 'cancel';
            $upds['is_sync'] = 'x';
            $upds['qty'] = $remoteOrder['OrderQty'];
            $upds['cancel_qty'] = $remoteOrder['CancelQty'];
            $upds['updated_at'] = $dt;
            $where = [];
            $where['id'] = $order['id'];
            $tmp = pdo_update_sql('orders', $upds, false, false, $where);
            $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
            $sth2->execute($tmp['params']);
            addOpLog(['message' => "PCHome: 訂單 ".$order['third_no'].' 已取消']);
        } elseif ($my_status == 'shipped') {
            // 已出貨
            // 更新
            $upds = [];
            $upds['third_status'] = $third_status;
            $upds['my_status'] = 'shipped';
            $upds['is_sync'] = 'xx';
            $upds['updated_at'] = $dt;
            $where = [];
            $where['id'] = $order['id'];
            $tmp = pdo_update_sql('orders', $upds, false, false, $where);
            $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
            $sth2->execute($tmp['params']);
            addOpLog(['message' => "PCHome: 訂單 ".$order['third_no'].' 已出貨']);
        } elseif ($my_status == 'new') {
            if ($order['is_sync'] != 'n') {
                continue;
            }
            $yy = $myYahoo->getByPart($yahooShopping, $order['part']);
            $tmpYId = '';
            foreach ($yy as $ii => $vv) {
                if ($vv['_attr2'] == $order['attr2']) {
                    $tmpYId = $vv['id'];
                    break;
                }
            }
            if ($tmpYId) {
                $upds = [];
                $upds['y_id'] = $tmpYId;
                $upds['updated_at'] = $dt;
                $where = [];
                $where['id'] = $order['id'];
                $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
                $sth2->execute($tmp['params']);
                //
                $strSQL = "SELECT * FROM orders WHERE id = :id ";
                $sth3 = $GLOBALS['dbh']->prepare($strSQL);
                $sth3->execute(['id' => $order['id']]);
                $order = $sth3->fetch();
                $opQty = $order['qty'];
                $cancelQty = $order['cancel_qty'];
                if ($opQty - $cancelQty == 0) {
                    $upds = [];
                    $upds['third_status'] = $third_status;
                    $upds['is_sync'] = 'y';
                    $upds['updated_at'] = $dt;
                    $where = [];
                    $where['id'] = $order['id'];
                    $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                    $sth4 = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth4->execute($tmp['params']);
                    echo $order['id']."退貨<br>\r\n";
                } else {
                    // 扣庫存
                    $yStock = $yahooShopping->Stock_GetQty($order['y_id']);
                    $updQty = $yStock - $order['qty'];
                    $updQty = $updQty < 0 ? 0 : $updQty;
                    //
                    if (app_env == 'production') {
                        $upds = [];
                        $upds['ProductId'] = $order['y_id'];
                        $upds['Qty'] = $opQty * -1;
                        $res = $yahooShopping->Stock_UpdateQty($upds);
                        $yStock = $yahooShopping->Stock_GetQty($order['y_id']);
                    }
                    //
                    $strSQL = "SELECT * FROM all_products WHERE y_id = :y_id ";
                    $sth5 = $GLOBALS['dbh']->prepare($strSQL);
                    $sth5->execute(['y_id' => $order['y_id']]);
                    $product = $sth5->fetch();
                    if (! $product) {
                        $adds = [];
                        $adds['part'] = $order['part'];
                        $adds['match_str'] = $order['match_str'];
                        $adds['attr2'] = $order['attr2'];
                        $adds['y_id'] = $order['y_id'];
                        $adds['p_id'] = $order['p_id'];
                        $adds['updated_at'] = $dt;
                        $tmp = pdo_insert_sql('all_products', $adds);
                        $sth6 = $GLOBALS['dbh']->prepare($tmp['sql']);
                        $sth6->execute($tmp['params']);
                    }
                    //
                    $upds = [];
                    $upds['local_stock'] = $yStock;
                    $upds['y_stock'] = $yStock;
                    $upds['p_last_order_id'] = $order['id'];
                    $upds['p_last_order_no'] = $order['third_no'];
                    $upds['p_last_order_date'] = $order['third_create_date'];
                    $where = [];
                    $where['y_id'] = $order['y_id'];
                    $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
                    $sth7 = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth7->execute($tmp['params']);
                    //
                    $upds = [];
                    $upds['third_status'] = $third_status;
                    $upds['is_sync'] = 'y';
                    $upds['updated_at'] = $dt;
                    $where = [];
                    $where['id'] = $order['id'];
                    $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                    $sth4 = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth4->execute($tmp['params']);
                    //
                    $txt = "PCHome: 訂單 ".$order['third_no']." ".$order['part'].'+'.$order['attr2']." Y庫存-".$opQty."=" .$yStock;
                    addOpLog(['message' => $txt]);
                }
            } else {
                $upds = [];
                $upds['is_sync'] = 'xx';
                $upds['updated_at'] = $dt;
                $where = [];
                $where['id'] = $order['id'];
                $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
                $sth2->execute($tmp['params']);
            }
        }
    }
    addOpLog(['message' => "PCHome 同步庫存 End"]);
}
