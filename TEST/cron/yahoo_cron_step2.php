<?php
/**
 * Yahoo 同步排程, 扣除其他平台
 */

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');



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
    $myYahoo = new MyYahoo;
    //
    $strSQL = "SELECT * FROM orders WHERE third_channel = 'yahoo' ";
    $strSQL .= "AND is_sync = 'n' ";
    $strSQL .= "AND my_status = 'new' ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute();
    while ($order = $sth->fetch()) {
        $dt = date('Y-m-d H:i:s');        
        // M庫存扣除
        self_check_momo($order);
        // P庫存扣除
        self_check_pchome($order);
    }
    echo "Yahoo 訂單同步 End\r\n";
}




/**
 * 已經配對到MOMO商品直接更新數量
 */
function self_UpdMomoProduct($product, $updQty)
{
    $myMomo = new MyMomo;
    $momoShopping = new MomoShopping;

    $mStock = $myMomo->getAttrQty($product['goods_code'], $product['goodsdt_info']);
    if (! is_numeric($mStock)) {
        $txt = "MOMO#".$product['part']."+" .$product['goodsdt_info']. " 無法取得庫存 ";
        addOpLog(['message' => $txt]);
        return 'xxx';
    }
    // 刷庫存
    $mStock = self_momoUpdQty($product['goods_code'], $product['goods_name'], $product['goodsdt_code'], $product['goodsdt_info'], $updQty);
    return $mStock;
}


/**
 * @param int $updQty 變更為這個庫存數量
 * @return int 線上MOMO庫存
 */
function self_momoUpdQty($goods_code, $goods_name, $goodsdt_code, $goodsdt_info, $updQty)
{
    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;
    $remoteQty = $myMomo->getAttrQty($goods_code, $goodsdt_info);
    if (is_numeric($remoteQty)) {
        if ($updQty > $remoteQty) {
            // 假設 updQty 4, mQty 1, opQty 3
            $opQty = $updQty - $remoteQty;
        } elseif ($updQty < $remoteQty) {
            // 假設 updQty 1, mQty 3, opQty -2
            $opQty = ($remoteQty - $updQty) * -1;
        } elseif ($updQty == $remoteQty) {
            //
        }
        if (isset($opQty)) {
            $params = [];
            $params['goodsCode'] = $goods_code;
            $params['goodsName'] = $goods_name;
            $params['goodsdtCode'] = $goodsdt_code;
            $params['goodsdtInfo'] = $goodsdt_info;
            $params['orderCounselQty'] = $updQty;
            $params['addReduceQty'] = $opQty;
            if (app_env == 'production') {
                momoUpdQty($params);
            }
        }
        // 會傳執行後取得的MOMO庫存
        $remoteQty = $myMomo->getAttrQty($goods_code, $goodsdt_info);
    }
    return $remoteQty;
}

function self_check_momo($order)
{
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    $myYahoo = new MyYahoo;

    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;
    
    $pchomeShopping = new PCHomeShopping;
    $myPCHome = new MyPCHome;

    //
    $opQty = $order['qty'];

    // 配對MOMO
    $isMatch = false;
    $momos = $momoShopping->getProducts($order['part']);
    foreach ($momos as $i => $momo) {
        $mPart = $momo['entp_goods_no'];
        $goods_code = $momo['goods_code'];
        $goods_name = $momo['goods_name'];
        $goodsdt_code = $momo['goodsdt_code'];
        $goodsdt_info = $momo['goodsdt_info'];
        $sale_gb_name = $momo['sale_gb_name']; // [進行] [暫時中斷]
        $attr2 = $myMomo->getAttr2($goodsdt_info);

        if (count($momos) == 1) {
            // do nothing.
        } elseif ($sale_gb_name != '進行') {
            continue;
        }
        if ($mPart != $order['part']) {
            continue;
        }

        $isPass = false;
        if (mb_strpos($goodsdt_info, '/', 0, 'utf-8') !== false) {
            if ($attr2 == $order['attr2']) {
                $isPass = true;
            }
        } else {
            $isPass = true;
        }
        if (! $isPass) {
            continue;
        }
        // 
        $mStock = $myMomo->getAttrQty($goods_code, $goodsdt_info);
        $updQty = $mStock - $opQty;
        $updQty = $updQty < 0 ? 0 : $updQty;
        // MOMO
        $tmp = [];
        $tmp['goods_code'] = $goods_code;
        $tmp['goods_name'] = $goods_name;
        $tmp['goodsdt_code'] = $goodsdt_code;
        $tmp['goodsdt_info'] = $goodsdt_info;
        $tmp['part'] = $order['part'];
        $rQty = self_UpdMomoProduct($tmp, $updQty);
        if (is_numeric($rQty)) {
            $isMatch = true;
        }
        //
        $txt = "Yahoo: 訂單 ".$order['third_no']." ".$order['part'].'+'.$order['attr2']." M庫存-" .$opQty. "=" .$updQty;
        addOpLog(['message' => $txt]);
        break;
    } // 配對MOMO

    $upds = [];
    $upds['is_sync'] = $isMatch ? 'y' : 'xx';
    if ($isMatch) {
        $upds['goods_code'] = $goods_code;
        $upds['goods_name'] = $goods_name;
        $upds['goodsdt_code'] = $goodsdt_code;
        $upds['goodsdt_info'] = $goodsdt_info;
    }
    $where = [];
    $where['id'] = $order['id'];
    $tmp = pdo_update_sql('orders', $upds, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
}

function self_check_pchome($order)
{
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    $myYahoo = new MyYahoo;

    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;
    
    $pchomeShopping = new PCHomeShopping;
    $myPCHome = new MyPCHome;
    //
    $opQty = $order['qty'];
    //
    $strSQL = "SELECT * FROM p_products WHERE part = :part ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute([
        'part' => $order['part'],
    ]);
    while ($item = $sth->fetch()) {
        if ($order['attr2'] == $item['attr2']) {
            $pchome = $item;
            break;
        }
    }
    //
    if (! empty($pchome)) {
        $tmp = $pchomeShopping->getProducts($pchome['pchome_id']);
        $tmp = $tmp[0];
        $onlineQty = $tmp['Qty'];
        $updQty = $onlineQty - $opQty;
        if ($updQty < 1) { $updQty = 0; }
        $pchomeShopping->updQty($pchome['pchome_id'], $updQty);
        //
        $upds = [];
        $upds['is_sync'] = 'y';
        $upds['p_id'] = $pchome['pchome_id'];
        $where = [];
        $where['id'] = $order['id'];
        $tmp = pdo_update_sql('orders', $upds, false, false, $where);
        $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth->execute($tmp['params']);
    }
}