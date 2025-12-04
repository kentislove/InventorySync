<?php
/**
 * MOMO 已出貨訂單
 * 入庫並配對Yahoo.id
 */

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

use App\Libraries\FetchHttp;



$startTime = time();
echo "MOMO 開始: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB ".PHP_EOL;
MOMO_GetPreparingOrders();
echo "結束: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB, 時間: " .(time() - $startTime). PHP_EOL;
function MOMO_GetPreparingOrders()
{
    $momoShopping = new MomoShopping;
    $momoLoginInfo = $momoShopping->loginInfo;

    $myMomo = new MyMomo;

    $yahooShopping = new YahooShopping;
    $yahooShopping->login();

    $myYahoo = new myYahoo;

    $fetch = new FetchHttp;
    // 
    $startDate = date('Y/m/d', strtotime('-5 day'));
    $endDate = date('Y/m/d');
    //
    $params = [
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
    
    $doActions = [];
    $doActions[] = 'unsendCompanyQuery'; // 1 未出貨訂單-廠商配送-查詢(資料加密)
    $doActions[] = 'unsendThirdQuery'; // 8 未出貨訂單-第三方物流-查詢

    $orders = [];
    foreach ($doActions as $i => $doAction) {
        $posts = [];
        $posts['doAction'] = $doAction;
        $posts['loginInfo'] = $momoLoginInfo;
        
        if ($doAction == 'unsendCompanyQuery') {

            $posts['sendInfo'] = getSendInfo_unsendCompanyQuery();
            $tmp = momo_GetAPI($posts);
            $res = $tmp['res'];
            $key = $tmp['key'];
            $output = json_decode($res['output'], true);
            $encrypted = $output['dataList'] ?? '';
            $decrypted = openssl_decrypt(
                base64_decode($encrypted),
                "AES-128-ECB",
                $key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING // 視實際 padding 而定
            );
            // $decrypted 是陣列

        } elseif ($doAction == 'unsendThirdQuery') {
            // 有4個物流方式要撈, *[61: 宅配通] [62: 新竹] [63: 宅急便] [65:]
            $gMoDeliveries = [];
            $gMoDeliveries[61] = '宅配通';
            $gMoDeliveries[62] = '新竹';
            $gMoDeliveries[63] = '宅急便';
            $gMoDeliveries[65] = '..文件沒更新';
            $third_delyGbs = array_keys($gMoDeliveries);

            foreach ($third_delyGbs as $gb) {
                $params = [];
                $params['third_delyGb'] = $gb;

                $posts['sendInfo'] = getSendInfo_unsendThirdQuery($params);

                $tmp = momo_GetAPI($posts);
                $res = $tmp['res'];
                $output = json_decode($res['output'], true);
                $data = $output['dataList'] ?? [];
                foreach ($data as $ii => $vv) {
                    $orderNo = $vv['completeOrderNo'];
                    // 
                    $orders[$orderNo] = $vv;
                }
            }
            // 把未出貨訂單入庫
            foreach ($orders as $orderNo => $order) {
                if (app_env == 'production') {
                    $logPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/log/m_order/'.$orderNo.'.log';
                    file_put_contents($logPath, json_encode($order, JSON_UNESCAPED_UNICODE));
                }
                $dt = date('Y-m-d H:i:s');

                // 訂單已存在
                $strSQL = "SELECT * FROM orders WHERE third_channel = :third_channel AND third_no = :third_no ";
                $sth = $GLOBALS['dbh']->prepare($strSQL);
                $sth->execute([
                    'third_channel' => 'momo',
                    'third_no' => $orderNo,
                ]);
                $exist = $sth->fetch();
                if (! $exist) {
                    //
                    $part = $order['entpGoodsNo'];
                    $goods_code = $order['goodsCode'];
                    $goods_name = $order['goodsName'];
                    $goodsdt_code = $order['goodsDtCode'];
                    $goodsdt_info = $order['goodsDtInfo'];
                    $matchStr = $myMomo->getMatchStr($goodsdt_info);
                    $attr1 = $myMomo->getAttr1($goodsdt_info);
                    $attr2 = $myMomo->getAttr2($goodsdt_info);
                    //
                    $orderTypeStr = $order['orderGbStr']; // 一般訂單
                    //
                    $orderDate = $order['lastPricDate']; // 轉單日, 2025/11/04 10:35, 對沒有秒
                    $orderDate = str_replace('/', '-', substr($orderDate, 0, 10));
                    //
                    $qty = $order['syslast']; // 數量

                    $adds = [];
                    $adds['third_channel'] = 'momo';
                    $adds['third_no'] = $orderNo;
                    $adds['third_create_date'] = $orderDate;
                    $adds['third_status'] = $orderTypeStr;
                    $adds['my_status'] = 'new';
                    $adds['part'] = $part;
                    $adds['match_str'] = $matchStr;
                    $adds['attr1'] = $attr1;
                    $adds['attr2'] = $attr2;
                    $adds['name'] = $goods_name;
                    $adds['qty'] = $qty;
                    $adds['goods_code'] = $goods_code;
                    $adds['goods_name'] = $goods_name;
                    $adds['goodsdt_code'] = $goodsdt_code;
                    $adds['goodsdt_info'] = $goodsdt_info;
                    $adds['created_at'] = $dt;
                    $adds['updated_at'] = $dt;
                    $tmp = pdo_insert_sql('orders', $adds);
                    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth->execute($tmp['params']);
                    $rowCount = $sth->rowCount();
                    if ($rowCount) {
                        $lastOrderId = $GLOBALS['dbh']->lastInsertId();
                        //
                        $txt = 'MOMO: 新訂單 '.$orderNo.'#'.$part.'+' .$goodsdt_info.' '.$orderDate;
                        addOpLog(['message' => $txt]);
                        //
                        $product = self_GetYahooProduct($part, $goods_code, $goodsdt_info);
                        if (! $product) {
                            $txt = "MOMO: #".$part."+" .$goodsdt_info. " 配對不到，無法同步庫存 #125";
                            addOpLog(['message' => $txt]);
                            continue;
                        }
                        //
                        $upds = [];
                        $upds['y_id'] = $product['y_id'];
                        $where = [];
                        $where['id'] = $lastOrderId;
                        $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                        $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
                        $sth2->execute($tmp['params']);
                        $cnt = $sth2->rowCount();
                    }
                }
            }
        }   
    }
    $txt = "MOMO 檢查待出貨 End";
    addOpLog(['message' => $txt]);
}





/**
 * Yahoo.id
 */
function self_GetYahooProduct($part, $goods_code, $goodsdt_info)
{
    $dt = date('Y-m-d H:i:s');
    
    $yahooShopping = new YahooShopping;
    $yahooShopping->login();
    
    $myYahoo = new MyYahoo;

    $myMomo = new MyMomo;
    $mAttr1 = $myMomo->getAttr1($goodsdt_info);
    $mAttr2 = $myMomo->getAttr2($goodsdt_info);
    $mMatchStr = $myMomo->getMatchStr($goodsdt_info);
    //
    $tmpY = $myYahoo->getYIdByMomoGoodsCode($part, $goods_code, $goodsdt_info);
    $yId = $tmpY['y_id'] ?? '';
    if ($yId == '') {
        $txt = "MOMO:".$part."+" .$goodsdt_info. " 配對不到，無法同步庫存 #388";
        addOpLog(['message' => $txt]);
        return [];
    }
    $matchStr = $tmpY['y_match_str'];
    $yAttr1 = $tmpY['y_attr1'];
    $yAttr2 = $tmpY['y_attr2'];
    $yStock = $tmpY['y_stock'];

    $strSQL = "SELECT * FROM all_products WHERE y_id = :y_id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['y_id' => $yId]);
    $product = $sth->fetch();
    if (! $product) {
        //
        $adds = [];
        $adds['part'] = $part;
        $adds['match_str'] = $matchStr;
        $adds['attr1'] = $yAttr1;
        $adds['attr2'] = $yAttr2;
        $adds['local_stock'] = $yStock;
        $adds['y_stock'] = $yStock;
        $adds['goods_code'] = $goods_code;
        $adds['goodsdt_info'] = $goodsdt_info;
        //
        $mStock = $myMomo->getAttrQty($goods_code, $goodsdt_info);
        if (is_numeric($mStock)) {
            $adds['m_stock'] = $mStock;
        }
        $adds['y_id'] = $yId;
        $adds['updated_at'] = $dt;
        $tmp = pdo_insert_sql('all_products', $adds);
        $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth->execute($tmp['params']);
    } else {
        $upds = [];
        $upds['match_str'] = $matchStr;
        $upds['attr1'] = $yAttr1;
        $upds['attr2'] = $yAttr2;
        $upds['updated_at'] = $dt;
        $where = [];
        $where['y_id'] = $yId;
        $tmp = pdo_update_sql('all_products', $upds, false, false, $where);
        $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth->execute($tmp['params']);
    }
    $strSQL = "SELECT * FROM all_products WHERE y_id = :y_id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['y_id' => $yId]);
    $product = $sth->fetch();
    return $product;
}


/**
 * 1 未出貨訂單-廠商配送-查詢(資料加密)
 */
function getSendInfo_unsendCompanyQuery()
{
    $sendInfo = [];
    $sendInfo['company_fr_dd'] = date('Y/m/d', strtotime('-5 day'));
    // $sendInfo['company_fr_dd'] = "2025/07/01";
    $sendInfo['company_fr_hh'] = '00';
    $sendInfo['company_fr_mm'] = '00';
    $sendInfo['company_to_dd'] = date('Y/m/d');
    // $sendInfo['company_to_dd'] = "2025/09/01";
    $sendInfo['company_to_hh'] = '23';
    $sendInfo['company_to_mm'] = '59';

    // 空值也要帶這四個參數
    $sendInfo['company_receiver'] = ''; // 收件人姓名, 可前後模糊搜尋
    $sendInfo['company_goodsCode'] = ''; // 商品編號, 可前後模糊搜尋
    $sendInfo['company_orderNo'] = ''; // 訂單編號, 可前後模糊搜尋
    $sendInfo['company_entpGoodsNo'] = ''; // 原廠編號, 可前後模糊搜尋
    $sendInfo['company_orderGb'] = ''; // 訂單類別
    return $sendInfo;
}

/**
 * 8
 * @param int third_delyGb *要全跑過
 */
function getSendInfo_unsendThirdQuery($params = [])
{
    $sendInfo = [];
    $sendInfo['third_fr_dd'] = date('Y/m/d', strtotime('-5 day'));
    $sendInfo['third_fr_hh'] = '00';
    $sendInfo['third_fr_mm'] = '00';
    $sendInfo['third_to_dd'] = date('Y/m/d');
    $sendInfo['third_to_hh'] = '23';
    $sendInfo['third_to_mm'] = '59';

    // 空值也要帶這四個參數
    $sendInfo['third_receiver'] = ''; // 收件人姓名, 可前後模糊搜尋
    $sendInfo['third_goodsCode'] = ''; // 商品編號, 可前後模糊搜尋
    $sendInfo['third_orderNo'] = ''; // 訂單編號, 可前後模糊搜尋
    $sendInfo['third_entpGoodsNo'] = ''; // 原廠編號, 可前後模糊搜尋
    $sendInfo['third_orderGb'] = ''; // *訂單類別, [全部:空值]
    $sendInfo['third_delyGb'] = $params['third_delyGb']; // * [61: 宅配通] [62: 新竹] [63: 宅急便]
    $sendInfo['third_delyTemp'] = '01'; // * [01: 常溫] [02: 冷凍] [03: 冷藏]
    return $sendInfo;
}
function momo_GetAPI($posts)
{
    $uri = 'https://scmapi.momoshop.com.tw';
    $apiUri = $uri.'/OrderServlet.do';
    
    $momoShopping = new MomoShopping;
    $momoLoginInfo = $momoShopping->loginInfo;

    $fetch = new FetchHttp;

    $headers = [];
    $headers['Content-Type'] = 'application/json';

    $extras = [];
    $extras['headers'] = $headers;
    //
    $master = $momoLoginInfo['entpID'];
    $master16 = str_pad($master, 16, 0);
    $password = $momoLoginInfo['entpPwd'];
    $password16 = str_pad($password, 16, 0);
    $key = $password16;

    $res = $fetch->httpPost($apiUri, json_encode($posts), $extras);
    return [
        'res' => $res,
        'key' => $key,
    ];
}