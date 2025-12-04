<?php

header("Content-Type: application/json; charset=utf-8");

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');


$data = [];
if (! empty($_POST)) {
    switch ($_POST['action']) {
        case 'api':
            $data = doApi($_POST);
            break;
        case 'sync':
            $data = doSync($_POST);
            break;
        case 'match':
            $data = doMatch($_POST);
            break;
        case 'momo_testing':
            $data = momoTesting();
            break;
        case 'upd_momo':
            $data = updMomo($_POST);
            break;
        case 'upd_yahoo':
            $data = updYahoo($_POST);
            break;
    }
}
echo json_encode($data);

function updYahoo($post)
{
    if (! is_numeric($post['upd_qty']) || $post['upd_qty'] == '') {
        return [
            'success' => false,
            'message' => '必須輸入數字',
        ];
    }
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    $myYahoo = new MyYahoo;

    $remote = $myYahoo->getById($yahooShopping, $post['y_id']);
    $nowQty = $remote['_stock'];
    if ($post['upd_qty'] == $nowQty) {
        return [
            'success' => false,
            'message' => '與線上庫存相同，不需變更',
        ];
    }
    $opQty = $post['upd_qty'] - $nowQty; // 扣庫存需為負數

    //
    $params = [];
    $params['ProductId'] = $post['y_id'];
    $params['Qty'] = $opQty;
    $yahooShopping->Stock_UpdateQty($params);
    
    $onlineQty = $yahooShopping->Stock_GetQty($post['y_id']);
    if ($onlineQty == $post['upd_qty']) {
        addOpLog(['message' => 'Yahoo: 人員變更庫存 ' .$remote['partNo']. ' (' .$post['y_id']. ') '. ' '.$nowQty.' -> '.$onlineQty]);
    }
    return [
        'success' => true,
        'onlineQty' => $onlineQty,
        'opQty' => $opQty,
    ];
}
function updMomo($post)
{
    if (! is_numeric($post['upd_qty']) || $post['upd_qty'] == '') {
        return [
            'success' => false,
            'message' => '必須輸入數字',
        ];
    }

    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;

    $nowQty = $myMomo->getAttrQty($post['goods_code'], $post['goodsdt_info']);
    $nowQty = is_numeric($nowQty) ? $nowQty : '--';
    if (! is_numeric($nowQty)) {
        return [
            'success' => false,
            'message' => '查無庫存',
        ];
    }
    if ($post['upd_qty'] == $nowQty) {
        return [
            'success' => false,
            'message' => '與線上庫存相同，不需變更',
        ];
    }

    $opQty = $post['upd_qty'] - $nowQty; // 扣庫存需為負數

    $params = [];
    $params['goodsCode'] = $post['goods_code'];
    $params['goodsName'] = $post['goods_name'];
    $params['goodsdtCode'] = $post['goodsdt_code'];
    $params['goodsdtInfo'] = $post['goodsdt_info'];;
    $params['orderCounselQty'] = $post['upd_qty'];
    $params['addReduceQty'] = $opQty;
    momoUpdQty($params);

    $onlineQty = $myMomo->getAttrQty($post['goods_code'], $post['goodsdt_info']);
    if ($onlineQty == $post['upd_qty']) {
        addOpLog(['message' => 'MOMO: 人員變更庫存 ' .$remote['partNo']. ' (' .$post['goods_code']. ') '. ' '.$nowQty.' -> '.$onlineQty]);
    }
    return [
        'success' => true,
        'onlineQty' => $onlineQty,
    ];
}

function momoTesting()
{
    $momoShopping = new MomoShopping;
    $data = $momoShopping->getQty('14562564');
    $message = $data ? '測試連線成功' : '測試連線失敗';
    addOpLog(['message' => 'MOMO: '.$message]);
    return [
        'success' => true,
        'message' => $message,
    ];
}
function doMatch($post)
{
    $strSQL = "SELECT * FROM m_products WHERE id = :id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $post['id']]);
    $data = $sth->fetch();
    if (! $data) {
        return [
            'success' => false,
            'message' => '資料有誤',
        ];
    }

    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;
    $myYahoo = new MyYahoo;
    //
    $res = $yahooShopping->getProducts(['y_part' => $data['part']]);
    $yProducts = $res['products'];
    
    $matchResult = $myYahoo->isMatchByGoodsdtInfo($yProducts, $data['goodsdt_info']);
    if ($matchResult['is_match']) {
        // $tmp_match_method = $matchResult['tmp_match_method'];
        // $yStock = $yahooShopping->Stock_GetQty($data['y_id']);
        $yProduct = $matchResult['product'];
        $yName = $myYahoo->getName($yProduct);
        $yId = $yProduct['id'];

        $dt = date('Y-m-d H:i:s');
        // 抓入初始數量
        $updates = [];
        $updates['y_id'] = $yId;
        $updates['updated_at'] = $dt;

        $where = [];
        $where['id'] = $data['id'];
        $tmp = pdo_update_sql('m_products', $updates, false, false, $where);
        $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth2->execute($tmp['params']);
        $cnt = $sth2->rowCount();
        return [
            'success' => true, 
            'message' => '配對OK',
        ];
    }
    return [
        'success' => false, 
        'message' => "配對不到，Yahoo規格如下\r\n".implode("\r\n", $matchResult['yMatches']),
    ];
}
function doApi($post)
{
    $strSQL = "SELECT * FROM m_products WHERE id = :id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $post['id']]);
    $data = $sth->fetch();
    if (! $data) {
        return [
            'success' => false,
            'message' => '資料有誤',
        ];
    }

    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;

    
    $yStock = $yahooShopping->Stock_GetQty($data['y_id']);
    $mStock = $myMomo->getAttrQty($data['goods_code'], $data['goodsdt_info']);
    
    $messages = [];
    $messages[] = 'Y: '.$yStock;
    $messages[] = 'M: '.$mStock;
    
    $updates = [];
    $updates['tmp_y_stock'] = $yStock;
    $updates['tmp_m_stock'] = $mStock;
    $where = [];
    $where['id'] = $data['id'];
    $tmp = pdo_update_sql('m_products', $updates, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
    $cnt = $sth->rowCount();
    return [
        'success' => true,
        'message' => implode("\r\n", $messages), 
    ];
}
// 當庫存<=1時：Yahoo=1, momo=0, pchome=0
// $yId = 34919839 ,,,, 2410382-20....卡其色 8.... 庫存 1
function doSync($post)
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
    $strSQL = "SELECT * FROM m_products WHERE id = :id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['id' => $post['id']]);
    $data = $sth->fetch();
    if (! $data) {
        return [
            'success' => false,
            'message' => '資料有誤 #160',
            'dd' => $post,
        ];
    }
    $yahoo = new YahooShopping;
    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;
    // 設定Y庫存數
    $yQty = $yahoo->Stock_GetQty($data['y_id']);
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
        $updates['ProductId'] = $data['y_id'];
        $updates['Qty'] = $opQty;
        $res = $yahoo->Stock_UpdateQty($updates);
        addOpLog([
            'y_id' => $data['y_id'],
            'message' => '商品'.$data['part'].'+'.$data['goodsdt_info'].' 更新 Yahoo庫存: '.$post['updQty'],
        ]);
    }
    // 
    $zQty = $yahoo->Stock_GetQty($data['y_id']);
    $updates = [];
    $updates['tmp_y_stock'] = $zQty;
    $where = [];
    $where['y_id'] = $data['y_id'];
    $tmp = pdo_update_sql('m_products', $updates, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
    $cnt = $sth->rowCount();
    // 設定M庫存數
    $rMomoQty = $myMomo->getAttrQty($data['goods_code'], $data['goodsdt_info']);
    if (is_numeric($rMomoQty)) {
        // 設定M庫存數
        self_momoUpdQty($data['y_id'], $post['updQty']);
        addOpLog([
            'y_id' => $data['y_id'],
            'message' => '商品'.$data['part'].'+'.$data['goodsdt_info'].' 更新 MOMO庫存: '.$post['updQty'],
        ]);
    }
    return [
        'success' => true,
    ];

    // 設定P庫存數
    // addOpLog([
    //     'y_id' => $data['y_id'],
    //     'message' => '商品'.$data['part'].'+'.$data['goodsdt_info'].' 更新PCHome庫存: '.$post['updQty'],
    // ]);








    // $yQty = $yahoo->Stock_GetQty($data['y_id']);
    // if ($yQty <= 1) {
    //     $yUpdQty = 1;
    //     addOpLog([
    //         'y_id' => $data['y_id'],
    //         'message' => '商品'.$data['part'].'+'.$data['goodsdt_info'].' 更新MOMO庫存: '.$mUpdQty,
    //     ]);
    //     // $updates = [];
    //     // $updates['ProductId'] = $data['y_id'];
    //     // $updates['Qty'] = 1;
    //     // $res = $yahoo->Stock_UpdateQty($updates);
    //     //
    //     $mUpdQty = 0;
    //     addOpLog([
    //         'y_id' => $data['y_id'],
    //         'message' => '商品'.$data['part'].'+'.$data['goodsdt_info'].' 更新MOMO庫存: '.$mUpdQty,
    //     ]);
    //     // self_momoUpdQty($data['y_id'], $mUpdQty);


    //     //
    //     $updates = [];
    //     $updates['init_stock'] = $qty;
    //     $updates['y_stock'] = $yStock;
    //     $updates['y_name'] = $yName;
    //     $updates['updated_at'] = $dt;
    //     $where = [];
    //     $where['y_id'] = $data['y_id'];
    //     $tmp = pdo_update_sql('m_products', $updates, false, false, $where);
    //     $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
    //     $sth2->execute($tmp['params']);
    //     $cnt = $sth2->rowCount();
    //     return [
    //         'success' => true,
    //     ];
    // }
    // if ($data['init_stock'] != $qty) {
    //     // $updates = [];
    //     // $updates['ProductId'] = $data['y_id'];
    //     // $updates['Qty'] = -1;
    //     // $res = $yahoo->Stock_UpdateQty($updates);
    //     // //
    //     // $res = $yahoo->Stock_GetQty($data['y_id']);
    //     // $qty = $res['Qty'];
    //     // // momo
    //     // mUpdQty($post, $qty);
    // }

    // addOpLog([
    //     'y_id' => $data['y_id'],
    //     'message' => '商品'.$data['y_id'].' 更新庫存數量 '.$qty,
    // ]);
    // return [
    //     'success' => true,
    //     'dd' => $data,
    //     'yy' => $qty,
    // ];
}



/**
 * @param int $updQty 變更為這個庫存數量
 */
function self_momoUpdQty($yId, $updQty)
{
    $strSQL = 'SELECT * FROM m_products WHERE y_id = :y_id AND sale_gb_name = "進行" ';
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['y_id' => $yId]);
    $data = $sth->fetch();

    $momoShopping = new MomoShopping;
    $myMomo = new MyMomo;

    $remoteQty = $myMomo->getAttrQty($data['goods_code'], $data['goodsdt_info']);
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
            $params['goodsCode'] = $data['goods_code'];
            $params['goodsName'] = $data['m_name'];
            $params['goodsdtCode'] = $data['goodsdt_code'];
            $params['goodsdtInfo'] = $data['goodsdt_info'];
            $params['orderCounselQty'] = $updQty;
            $params['addReduceQty'] = $opQty;
            momoUpdQty($params);
        }
    }
    // 會傳執行後取得的MOMO庫存
    $remoteQty = $myMomo->getAttrQty($data['goods_code'], $data['goodsdt_info']);

    //
    $updates = [];
    $updates['tmp_m_stock'] = $remoteQty;
    $where = [];
    $where['y_id'] = $yId;
    $tmp = pdo_update_sql('m_products', $updates, false, false, $where);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
    $cnt = $sth->rowCount();
    //
    return $remoteQty;
}