<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=utf-8");

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

use App\Libraries\FetchHttp;


$data = [];
if (! empty($_POST)) {
    switch ($_POST['act']) {
        case 'sync':
            $data = doSync($_POST);
            break;
        case 'match':
            $data = doMatch($_POST);
            break;
        case 'realtime':
            $data = realtime($_POST);
            break;
        case 'upd_qty':
            $data = doUpdQty($_POST);
            break;
        case 'skip_sync':
            $data = doSkipSync($_POST);
            break;
    }
}
echo json_encode($data);

function doUpdQty($post)
{
    if ($post['updQty'] == '') {
        return [
            'success' => false,
            'message' => '請輸入要更新的庫存數量',
        ];
    }
    if ($post['updQty'] < 0) {
        return [
            'success' => false,
            'message' => '不能小於0',
        ];
    }

    $strSQL = "SELECT * FROM orders WHERE id = :id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $post['id']]);
    $order = $sth->fetch();
    if (! $order) {
        return [
            'success' => false,
            'message' => '資料有誤 #160',
            'dd' => $post,
        ];
    }
    $yahooShopping = new YahooShopping;
    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;
    // 設定Y庫存數
    $yQty = $yahooShopping->Stock_GetQty($order['y_id']);
    if ($post['updQty'] == $yQty) {
        // 
    } elseif ($post['updQty'] > $yQty) {
        $opQty = $post['updQty'] - $yQty;
    } elseif ($post['updQty'] < $yQty) {
        $opQty = $yQty - $post['updQty'];
        $opQty = $opQty * -1;
    }
    if (isset($opQty)) {
        $updates = [];
        $updates['ProductId'] = $order['y_id'];
        $updates['Qty'] = $opQty;
        if (app_env == 'production') {
            $res = $yahooShopping->Stock_UpdateQty($updates);
        }
        addOpLog([
            'y_id' => $order['y_id'],
            'message' => '商品'.$order['part'].'+'.$order['attr2'].' 更新 Yahoo庫存: '.$post['updQty'],
        ]);
    }
    // 
    $yQty = $yahooShopping->Stock_GetQty($order['y_id']);
    $updates = [];
    $updates['y_stock'] = $yQty;
    $where = [];
    $where['y_id'] = $order['y_id'];
    $tmp = pdo_update_sql('all_products', $updates, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
    $cnt = $sth->rowCount();
    // 設定M庫存數
    $mStock = $myMomo->getAttrQty($order['goods_code'], $order['goodsdt_info']);
    if (is_numeric($mStock)) {
        // 設定M庫存數
        if ($order['goods_code'] && $order['goods_name']) {
            $afterMQty = self_momoUpdQty($order['goods_code'], $order['goods_name'], $order['goodsdt_code'], $order['goodsdt_info'], $post['updQty']);
            addOpLog([
                'y_id' => $order['y_id'],
                'message' => '商品'.$order['part'].'+'.$order['attr2'].' 更新 MOMO庫存: '.$post['updQty'],
            ]);
            $updates = [];
            $updates['m_stock'] = $afterMQty;
            $where = [];
            $where['y_id'] = $order['y_id'];
            $tmp = pdo_update_sql('all_products', $updates, false, false, $where);
            $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
            $sth->execute($tmp['params']);
        }  
    }
    
    return [
        'success' => true,
    ];
}
function realtime($post)
{
    $strSQL = "SELECT * FROM orders WHERE id = :id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $post['id']]);
    $order = $sth->fetch();
    if (! $order) {
        return [
            'success' => false,
            'message' => '資料有誤',
        ];
    }

    $yahooShopping = new YahooShopping;
    $yahooShopping->login();

    $myYahoo = new MyYahoo;

    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;

    $pchomeShopping = new PCHomeShopping;
    $myPCHome = new MyPCHome;

    $yStock = '';
    $mStock = '';
    $pStock = '';

    //
    if ($order['y_id']) {
        $yStock = $yahooShopping->Stock_GetQty($order['y_id']);
    }
    //
    if ($order['goods_code']) {
        $mStock = $myMomo->getAttrQty($order['goods_code'], $order['goodsdt_info']);
        if (! is_numeric($mStock)) {
            addOpLog(['message' => '訂單: ' .$order['third_no'].' 查無 MOMO 即時庫存']);
        }
    }
    if ($order['p_id']) {
        $pStock = $myPCHome->getQty($pchomeShopping, $order['p_id']);
    }
    //
    $messages = [];
    if ($yStock) {
        $messages[] = 'Y: '.$yStock;
    }
    if (is_numeric($mStock)) {
        $messages[] = 'M: '.$mStock;
    }
    if ($pStock) {
        $messages[] = 'P: '.$pStock;
    }
    //
    $upds = [];
    if ($yStock != '') {
        $upds['y_stock'] = $yStock;
    }
    if ($mStock != '') {
        $upds['m_stock'] = is_numeric($mStock) ? $mStock : null;
    }
    if ($pStock != '') {
        $upds['p_stock'] = $pStock;
    }
    // if (! empty($upds)) {
    //     $where = [];
    //     $where['id'] = $order['id'];
    //     $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
    //     $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    //     $sth->execute($tmp['params']);
    //     $cnt = $sth->rowCount();

    //     // if ($pStock != '') {
    //     //     $upds = [];
    //     //     $upds['qty'] = $pStock;

    //     //     $where = [];
    //     //     $where['pchome_id'] = $order['p_id'];
    //     //     $tmp = pdo_update_sql('p_products', $upds, false, false, $where);
    //     //     $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    //     //     $sth->execute($tmp['params']);
    //     //     $cnt = $sth->rowCount();
    //     // }
    // }
    return [
        'success' => true,
        'message' => implode("\r\n", $messages), 
        'y_stock' => $yStock ?? '',
        'm_stock' => $mStock ?? '',
        'p_stock' => $pStock ?? '',
    ];
}


// 當庫存<=1時：Yahoo=1, momo=0, pchome=0
// $yId = 34919839 ,,,, 2410382-20....卡其色 8.... 庫存 1

/**
 * 重新配對 Y商品重新配對M，M商品重新配對Y
 * 
 * ＊已同步，不管照樣配對
 */
function doMatch($post)
{
    $orderId = $post['id'] ?? '';
    if (! $orderId) {
        return [
            'success' => false,
            'message' => '訂單不存在',
        ];
    }
    
    $strSQL = "SELECT * FROM orders WHERE id = :id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $orderId]);
    $order = $sth->fetch();
    if (! $order) {
        return [
            'success' => false,
            'message' => '資料有誤 #46',
        ];
    }
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    $myYahoo = new MyYahoo;

    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;

    // Yahoo單
    if ($order['third_channel'] == 'yahoo') {
        $remotes = $momoShopping->getProducts($order['part']);
        if (! $remotes) {
            // MOMO未上架
            // do nothing.
            addOpLog(['message' => 'Yahoo: 料號'.$order['part'].' 配對不到 MOMO 商品']);
            //
            self_UpdMessage($order['id'], $txt);
            return [
                'success' => true,
            ];
        }
        foreach ($remotes as $i => $v) {
            $part = $v['entp_goods_no'];
            $goods_code = $v['goods_code'];
            $goods_name = $v['goods_name'];
            $goodsdt_code = $v['goodsdt_code'];
            $goodsdt_info = $v['goodsdt_info'];
            $attr2 = $myMomo->getAttr2($goodsdt_info);
            //
            if ($part == $order['part']) {
                $isPass = false;
                if (mb_strpos($goodsdt_info, '/', 0, 'utf-8') !== false) {
                    if ($attr2 == $order['attr2']) {
                        $isPass = true;
                    }
                } else {
                    $isPass = true;
                }
                if ($isPass) {
                    $mQty = $myMomo->getAttrQty($goods_code, $goodsdt_info);
                    if (! is_numeric($mQty)) {
                        $txt = "MOMO#".$part."+" .$goodsdt_info. " 無法取得庫存 ";
                        addOpLog(['message' => $txt]);
                        //
                        self_UpdMessage($order['id'], $txt);
                        break;
                    }
                    $upds = [];
                    $upds['goods_code'] = $goods_code;
                    $upds['goodsdt_code'] = $goodsdt_code;
                    $upds['goodsdt_info'] = $goodsdt_info;
                    $where = [];
                    $where['id'] = $order['id'];
                    $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth->execute($tmp['params']);
                    //
                    // 入庫
                    $product = self_AddYahooProduct($order['y_id']);
                    return [
                        'success' => true,
                    ];
                }
            }
        }
    } elseif ($order['third_channel'] == 'momo') {
        //
        $mAttr2 = $myMomo->getAttr2($order['goodsdt_info']);
        //
        $remotes = $myYahoo->getByPart($yahooShopping, $order['part']);
        if (! $remotes) {
            addOpLog(['message' => 'MOMO: '.$order['part'].' 配對不到 Yahoo 商品']);
            return [
                'success' => true,
            ];
        }
        $isMatch = false;
        if ($mAttr2 == '' && count($remotes) == 1) {
            // 不分尺寸，只判斷料號
            $remote = $remotes[0];
            $yId = $remote['id'];
            $matchStr = $remote['_match_str'];
            $attr1 = $remote['_attr1'];
            $attr2 = $remote['_attr2'];
            $yStock = $remote['_stock'];
            $isMatch = true;
        } else {
            foreach ($remotes as $i => $v) {
                $yId = $v['id'];
                $remote = $myYahoo->getById($yahooShopping, $yId);
                $matchStr = $remote['_match_str'];
                $attr1 = $remote['_attr1'];
                $attr2 = $remote['_attr2'];
                $yStock = $remote['_stock'];
                if ($mAttr2 == $attr2) {
                    $isMatch = true;
                    break;
                }
            }
        }
        if ($isMatch) {
            $upds = [];
            $upds['y_id'] = $yId;
            $upds['match_str'] = $matchStr;
            $upds['attr1'] = $attr1;
            $upds['attr2'] = $attr2;
            $where = [];
            $where['id'] = $order['id'];
            $tmp = pdo_update_sql('orders', $upds, false, false, $where);
            $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
            $sth->execute($tmp['params']);
            // 入庫
            $product = self_AddYahooProduct($yId);
            return [
                'success' => true,
            ];
        } else {
            addOpLog(['message' => 'MOMO: '.$order['part'].' 配對不到 Yahoo 商品']);
            return [
                'success' => true,
            ];
        }
    }
}

function self_UpdMessage($orderId, $txt)
{
    $upds = [];
    $upds['message'] = $txt;
    $where = [];
    $where['id'] = $orderId;
    $tmp = pdo_update_sql('orders', $upds, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
}

// 當庫存<=1時：Yahoo=1, momo=0, pchome=0
// $yId = 34919839 ,,,, 2410382-20....卡其色 8.... 庫存 1
function doSync($post)
{
    $orderId = $post['id'] ?? '';
    if (! $orderId) {
        return [
            'success' => false,
            'message' => '訂單不存在',
        ];
    }
    
    $strSQL = "SELECT * FROM orders WHERE id = :id AND third_channel = 'yahoo' AND third_status = '待出貨' ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $orderId]);
    $yOrder = $sth->fetch();
    if ($yOrder) {
        return doYahooOrder($yOrder);
    }

    $strSQL = "SELECT * FROM orders WHERE id = :id AND third_channel = 'momo' ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $orderId]);
    $mOrder = $sth->fetch();
    if ($mOrder) {
        return doMomoOrder($mOrder);
    }
}

/**
 * Y取得線上庫存
 */
function doYahooOrder($order)
{
    if ($order['is_sync'] == 'y') {
        return [
            'success' => false,
            'message' => '已計算過，不允許重複計算',
        ];
    }

    $dt = date('Y-m-d H:i:s');

    $yahooShopping = new YahooShopping;
    $yahooShopping->login();

    $myYahoo = new MyYahoo;

    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;
    
    // 入庫
    $product = self_AddYahooProduct($order['y_id']);

    // Y單進來就是更新Y線上庫存
    $upds = [];
    $upds['local_stock'] = $product['y_stock'];
    $adds['updated_at'] = $dt;
    $where = [];
    $where['y_id'] = $order['y_id'];
    $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);

    // M庫存扣除
    $opQty = $order['qty'];

    // 配對MOMO
    $momos = $momoShopping->getProducts($order['part']);
    foreach ($momos as $i => $momo) {
        $mPart = $momo['entp_goods_no'];
        $goods_code = $momo['goods_code'];
        $goods_name = $momo['goods_name'];
        $goodsdt_code = $momo['goodsdt_code'];
        $goodsdt_info = $momo['goodsdt_info'];
        $sale_gb_name = $momo['sale_gb_name']; // [進行]
        if ($sale_gb_name == '進行') {
            if ($mPart == $order['part']) {
                $mMatchStr = $myMomo->getMatchStr($goodsdt_info);
                if ($order['match_str'] == $mMatchStr) {
                    // 配對到扣除Ｍ庫存
                    $mStock = $myMomo->getAttrQty($goods_code, $goodsdt_info);
                    $updQty = $mStock - $opQty;
                    $updQty = $updQty < 0 ? 0 : $updQty;
                    $txt = "MOMO#".$goods_code.':'.$order['part'].'+'.$order['match_str']." 庫存 ".$mStock.'-'.$opQty.'='.$updQty;
                    addOpLog(['message' => $txt]);

                    // MOMO
                    // self_UpdMomoProduct($product, $mUpdQty);

                    //
                    $upds = [];
                    $upds['m_stock'] = $updQty;
                    $upds['y_last_order_id'] = $order['id'];
                    $upds['y_last_order_no'] = $order['third_no'];
                    $upds['y_last_order_at'] = $order['third_create_date'];
                    $where = [];
                    $where['y_id'] = $order['y_id'];
                    $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
                    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth->execute($tmp['params']);
                    //
                    $upds = [];
                    // $upds['is_sync'] = 'y';
                    $upds['goods_code'] = $goods_code;
                    $upds['goods_name'] = $goods_name;
                    $upds['goodsdt_code'] = $goodsdt_code;
                    $upds['goodsdt_info'] = $goodsdt_info;
                    $where = [];
                    $where['id'] = $order['id'];
                    $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth->execute($tmp['params']);
                    break;
                }
            }
        }
    }
    return [
        'success' => true,
    ];
}

function doMomoOrder($order)
{
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    $myYahoo = new MyYahoo;
    //
    if ($order['is_sync'] == 'y') {
        return [
            'success' => false,
            'message' => '已計算過，不允許重複計算',
        ];
    }
    if (! $order['y_id']) {
        return [
            'success' => false,
            'message' => '請先配對預覽',
        ];
    }
    
    $product = self_AddYahooProduct($order['y_id']);
    $opQty = $order['qty'];

    // 線上Y庫存扣
    $yStock = $yahooShopping->Stock_GetQty($order['y_id']);
    $updQty = $yStock - $order['qty'];
    $updQty = $updQty < 0 ? 0 : $updQty;

    if (app_env == 'production') {
        $updates = [];
        $updates['ProductId'] = $order['y_id'];
        $updates['Qty'] = $opQty * -1;
        $res = $yahooShopping->Stock_UpdateQty($updates);
    }
    //
    $upds = [];
    $upds['y_stock'] = $updQty;
    $upds['m_last_order_id'] = $order['id'];
    $upds['m_last_order_no'] = $order['third_no'];
    $upds['m_last_order_date'] = $order['third_create_date'];
    $where = [];
    $where['y_id'] = $order['y_id'];
    $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
    //
    $txt = "MOMO訂單#".$order['third_no']." ".$order['part'].'+'.$product['attr2']." Y庫存: " .$updQty.'(-' .$opQty. ')';
    addOpLog(['message' => $txt]);
    //
    $upds = [];
    $upds['is_sync'] = 'n';
    $where = [];
    $where['id'] = $order['id'];
    $tmp = pdo_update_sql('orders', $upds, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
    return [
        'success' => true,
    ];
}


function self_AddYahooProduct($yId)
{
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();

    $myYahoo = new MyYahoo;

    $dt = date('Y-m-d H:i:s');
    //
    $remote = $myYahoo->getById($yahooShopping, $yId);
    $part = $remote['partNo'];
    $matchStr = $remote['_match_str'];
    $attr1 = $remote['_attr1'];
    $attr2 = $remote['_attr2'];
    $yStock = $remote['_stock'];
    //
    $strSQL = "SELECT * FROM all_products WHERE y_id = :y_id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['y_id' => $yId]);
    $data = $sth->fetch();
    if (! $data) {
        $adds = [];
        $adds['part'] = $part;
        $adds['match_str'] = $matchStr;
        $adds['attr1'] = $attr1;
        $adds['attr2'] = $attr2;
        $adds['local_stock'] = $yStock;
        $adds['y_stock'] = $yStock;
        $adds['y_id'] = $yId;
        $adds['updated_at'] = $dt;
        $tmp = pdo_insert_sql('all_products', $adds);
        $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth->execute($tmp['params']);
        //
        $strSQL = "SELECT * FROM all_products WHERE y_id = :y_id ";
        $sth = $GLOBALS['dbh']->prepare($strSQL);
        $sth->execute(['y_id' => $yId]);
        $data = $sth->fetch();
    } else {
        $upds = [];
        $upds['match_str'] = $matchStr;
        $upds['attr1'] = $attr1;
        $upds['attr2'] = $attr2;
        $upds['y_stock'] = $yStock;
        $where = [];
        $where['y_id'] = $yId;
        $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
        $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth->execute($tmp['params']);
    }
    return $data;
}

/**
 * 
 */
function self_UpdMomoProduct($product, $updQty)
{
    $myMomo = new MyMomo;
    $momoShopping = new MomoShopping;
    if (! $product['goods_code']) {
        $remotes = $momoShopping->getProducts($product['part']);
        if (! $remotes) {
            // MOMO未上架
            // do nothing.
            $txt = "MOMO#".$product['part']." 不存在 ";
            addOpLog(['message' => $txt]);
            return [
                'success' => true,
            ];
        }
        foreach ($remotes as $i => $v) {
            $part = $v['entp_goods_no'];
            $goods_code = $v['goods_code'];
            $goods_name = $v['goods_name'];
            $goodsdt_code = $v['goodsdt_code'];
            $goodsdt_info = $v['goodsdt_info'];
            $sale_gb_name = $v['sale_gb_name'];
            //
            if ($part == $product['part']) {
                $isNext = false;
                if (count($remotes) == 1) {
                    $isNext = true;
                } elseif ($sale_gb_name == '進行') {
                    $isNext = true;
                }
                if (! $isNext) {
                    continue;
                }
                $goodsdt_info = $goodsdt_info == '無' ? '' : $goodsdt_info;

                $arr = explode('/', $goodsdt_info);
                $attr1 = isset($arr[0]) ? trim($arr[0]) : '';
                $attr2 = isset($arr[1]) ? trim($arr[1]) : '';

                $mMatchStr = $attr1.' '.$attr2;
                if ($mMatchStr == $product['match_str']) {
                    $mQty = $myMomo->getAttrQty($goods_code, $goodsdt_info);
                    if (! is_numeric($mQty)) {
                        $txt = "MOMO#".$part."+" .$goodsdt_info. " 無法取得庫存 ";
                        addOpLog(['message' => $txt]);
                        break;
                    }
                    $upds = [];
                    $upds['m_stock'] = $mQty;
                    $upds['goods_code'] = $goods_code;
                    $upds['goodsdt_code'] = $goodsdt_code;
                    $upds['goodsdt_info'] = $goodsdt_info;
                    $where = [];
                    $where['y_id'] = $product['y_id'];
                    $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
                    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth->execute($tmp['params']);

                    // 刷庫存
                    self_momoUpdQty($goods_code, $goods_name, $goodsdt_code, $goodsdt_info, $updQty);
                    $txt = "MOMO#".$part."+" .$goodsdt_info. " 庫存數量(" .$mQty. ") 更新 " .$updQty. " ";
                    addOpLog(['message' => $txt]);
                    break;
                }
            }
        }
    }
}


/**
 * @param int $updQty 變更為這個庫存數量
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
        // //
        // $updates = [];
        // $updates['tmp_m_stock'] = $remoteQty;
        // $where = [];
        // $where['y_id'] = $yId;
        // $tmp = pdo_update_sql('m_products', $updates, false, false, $where);
        // $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        // $sth->execute($tmp['params']);
        // $cnt = $sth->rowCount();
    }
    return $remoteQty;
}

function doSkipSync($post)
{
    $strSQL = "SELECT * FROM orders WHERE id = :id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $post['id']]);
    $order = $sth->fetch();
    if (! $order) {
        return [
            'success' => true,
            'message' => '訂單不存在',
        ];
    }

    $upds = [];
    $upds['is_sync'] = 'y';
    $upds['updated_at'] = date('Y-m-d H:i:s');
    $where = [];
    $where['id'] = $post['id'];
    $tmp = pdo_update_sql('orders', $upds, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
    $cnt = $sth->rowCount();
    if ($cnt) {
        addOpLog(['message' => '訂單: ' .$order['third_no']. ' 略過同步']);
        // 抓Yahoo庫存為目前庫存
        if ($order['y_id']) {
            $yahooShopping = new YahooShopping;
            $yahooShopping->login();
            $yStock = $yahooShopping->Stock_GetQty($order['y_id']);

            $upds = [];
            $upds['local_stock'] = $yStock;
            $upds['y_stock'] = $yStock;
            $upds['m_stock'] = $yStock;
            $upds['updated_at'] = date('Y-m-d H:i:s');
            $where = [];
            $where['y_id'] = $order['y_id'];
            $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
            $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
            $sth->execute($tmp['params']);
            $cnt = $sth->rowCount();
            return [
                'success' => true,
            ];
        }
    }
    return [
        'success' => true,
    ];
}