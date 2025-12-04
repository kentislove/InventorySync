<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

/**
 * PCHome 訂單不分待出貨已出貨
 */
echo "開始: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB<br>\r\n";
PCHome_GetPreparingOrders();
echo "結束: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB<br>\r\n";
function PCHome_GetPreparingOrders()
{
    $params = [];
    $params['start_date'] = date('Y/m/d', strtotime('-3 day'));
    $params['end_date'] = date('Y/m/d');
    $params['offset'] = 0;
    $params['limit'] = 100;

    $pchomeShopping = new PCHomeShopping;
    $myPCHome = new MyPCHome;
    //
    $output = $pchomeShopping->getOrders($params);
    $cnt = $output['count'];
    $data = $output['data']; // 只有訂單編號
    $orderIds = array_column($data, 'Id');
    foreach ($orderIds as $i => $orderNo) {
        $dt = date('Y-m-d H:i:s');

        // if ($orderNo != '20251115987474-01') {
        //     continue;
        // }
        $infos = $pchomeShopping->getOrderInfo([$orderNo]);
        foreach ($infos as $ii => $info) {
            $orderDate = $myPCHome->getOrderDate($info);
            $qty = $info['OrderQty']; // 下訂數量
            $cancelQty = $info['CancelQty']; // 取消數量
            //
            $strSQL = "SELECT * FROM orders WHERE third_channel = :third_channel AND third_no = :third_no ";
            $sth = $GLOBALS['dbh']->prepare($strSQL);
            $sth->execute([
                'third_channel' => 'pchome',
                'third_no' => $orderNo,
            ]);
            $exist = $sth->fetch();
            if (! $exist) {
                // 商品
                $product = $info['Prod'];
                // 推測一單只有一個商品
                $pId = $product['Id'];
                $part = $product['VendorPID'];
                $name = $product['Name'];
                $matchStr = $product['SpecName'];
                $attr2 = $matchStr;

                $adds = [];
                $adds['third_channel'] = 'pchome';
                $adds['third_no'] = $orderNo;
                $adds['third_create_date'] = $orderDate;
                $adds['third_status'] = ''; // step2 做處理
                $adds['my_status'] = 'new';
                $adds['part'] = $part;
                $adds['match_str'] = $matchStr;
                $adds['attr1'] = '';
                $adds['attr2'] = $attr2;
                $adds['name'] = $name;
                $adds['qty'] = $qty;
                $adds['cancel_qty'] = $cancelQty;
                $adds['p_id'] = $pId;
                $adds['created_at'] = $dt;
                $adds['updated_at'] = $dt;
                $tmp = pdo_insert_sql('orders', $adds);
                $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
                $sth2->execute($tmp['params']);
                $rowCount = $sth2->rowCount();
                if ($rowCount) {
                    addOpLog(['message' => 'PCHome: 新訂單 '.$orderNo.', '.$part.', '.$orderDate]);
                }

                // 是否存在商品表
                $strSQL = "SELECT * FROM p_products WHERE pchome_id = :pchome_id ";
                $sth = $GLOBALS['dbh']->prepare($strSQL);
                $sth->execute([
                    'pchome_id' => $pId,
                ]);
                $exist = $sth->fetch();
                if (! $exist) {
                    $adds = [];
                    $adds['pchome_id'] = $pId;
                }
            }
        }
    }
}

