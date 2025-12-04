<?php
/**
 * MOMO
 * 20 出貨中訂單-超商取貨-查詢
 * 21 出貨中訂單-第三方物流
 * 22 出貨中訂單-訂單返回
 */

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

use App\Libraries\FetchHttp;



$startTime = time();
echo "開始: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB<br>\r\n";
MOMO_GetSendingOrders();
echo "結束: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB, 時間: " .(time() - $startTime). "<br>\r\n";
function MOMO_GetSendingOrders()
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
    // $doActions[] = 'sendingStoresQuery'; // 20 出貨中訂單-超商取貨-查詢
    $doActions[] = 'sendingThirdQuery'; // 21 出貨中訂單-第三方物流
    // $doActions[] = 'orderBackQuery'; // 22 出貨中訂單-訂單返回

    $orders = [];
    foreach ($doActions as $i => $doAction) {
        $posts = [];
        $posts['doAction'] = $doAction;
        $posts['loginInfo'] = $momoLoginInfo;
        
        if ($doAction == 'sendingStoresQuery') {
            // 狀態 [1:已印單待驗收] [2:已印單未到貨] [3:商品驗退需重新出貨] [4:待客戶取件] [5:進驗尚未配達門市]
            $tmpStatusOptions = [1,2,3,4,5];
            foreach ($tmpStatusOptions as $status) {
                $params[] = [];
                $params['status'] = $status;
                //
                $posts['sendInfo'] = getSendInfo_sendingStoresQuery($params);
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
                // echo "<pre>" .print_r($decrypted, true). "</pre>";
                // $decrypted 是陣列
            }
        } elseif ($doAction == 'sendingThirdQuery') {
            $gMoDeliveries = [];
            $gMoDeliveries[61] = '宅配通';
            $gMoDeliveries[62] = '新竹';
            $gMoDeliveries[63] = '宅急便';
            $gMoDeliveries[65] = '..文件沒更新';
            $third_delyGbs = array_keys($gMoDeliveries);

            foreach ($third_delyGbs as $gb) {
                $params = [];
                $params['third_delyGb'] = $gb;

                $posts['sendInfo'] = getSendInfo_sendingThirdQuery($params);

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
            // 變更訂單狀態
            foreach ($orders as $orderNo => $order) {
                $dt = date('Y-m-d H:i:s');
                //
                $upds = [];
                $upds['my_status'] = 'shipped';
                $upds['third_status'] = $order['order_gb_str'];
                $upds['updated_at'] = $dt;
                $where = [];
                $where['third_no'] = $orderNo;
                $tmp = pdo_update_sql('orders', $upds, false, false, $where);
                $sth2 = $GLOBALS['dbh']->prepare($tmp['sql']);
                $sth2->execute($tmp['params']);
                $cnt = $sth2->rowCount();
                if ($cnt) {
                    addOpLog(['message' => "MOMO: 訂單 ".$orderNo.' 已出貨']);
                }
            }
        } elseif ($doAction == 'orderBackQuery') {
            // do nothing.
        }
    }
    addOpLog(['message' => "MOMO 檢查已出貨 End"]);
}
















/**
 * 20
 * @param int status *要全跑過
 */
function getSendInfo_sendingStoresQuery($params = [])
{
    $sendInfo = [];
    $sendInfo['fromDate'] = date('Y/m/d', strtotime('-5 day')); // 最大兩個月
    $sendInfo['fromHour'] = '00';
    $sendInfo['fromMinute'] = '00';
    $sendInfo['toDate'] = date('Y/m/d');
    $sendInfo['toHour'] = '23';
    $sendInfo['toMinute'] = '59';

    // 空值也要帶這參數
    $sendInfo['qryGoodsCode'] = ''; // 商品編號, 可前後模糊搜尋
    $sendInfo['receiver'] = ''; // 收件人姓名, 可前後模糊搜尋
    $sendInfo['orderNo'] = ''; // 訂單編號, 可前後模糊搜尋
    $sendInfo['entpGoodsCode'] = ''; // 原廠編號, 可前後模糊搜尋
    $sendInfo['status'] = $params['status']; // *狀態 [1:已印單待驗收] [2:已印單未到貨] [3:商品驗退需重新出貨] [4:待客戶取件] [5:進驗尚未配達門市]

    return $sendInfo;
}

/**
 * 21
 * @param int third_delyGb *要全跑過
 */
function getSendInfo_sendingThirdQuery($params = [])
{
    $sendInfo = [];
    $sendInfo['fromDate'] = date('Y/m/d', strtotime('-5 day')); // 最大兩個月
    $sendInfo['fromHour'] = '00';
    $sendInfo['fromMinute'] = '00';
    $sendInfo['toDate'] = date('Y/m/d');
    $sendInfo['toHour'] = '23';
    $sendInfo['toMinute'] = '59';

    // 空值也要帶這參數
    $sendInfo['qryGoodsCode'] = ''; // 商品編號, 可前後模糊搜尋
    $sendInfo['receiver'] = ''; // 收件人姓名, 可前後模糊搜尋
    $sendInfo['status'] = '1'; // *狀態 [1:已印單] [2:配送中]
    $sendInfo['orderNo'] = ''; // 訂單編號, 可前後模糊搜尋
    $sendInfo['logistics'] = $params['third_delyGb']; // *物流商, [61:宅配通] [62:新竹貨運] [63:宅急便]
    $sendInfo['entpGoodsCode'] = ''; // 原廠編號, 可前後模糊搜尋
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