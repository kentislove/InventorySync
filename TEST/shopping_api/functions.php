<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once($_SERVER['DOCUMENT_ROOT'].'/libs/FetchHttp.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/db_functions.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/lib/AES_OpenSSL.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/lib/PCHomeShopping.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/YahooShopping.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/MomoShopping.php');

use App\Libraries\FetchHttp;

if (! isset($_SESSION)) {
    session_start();
}

date_default_timezone_set('Asia/Taipei');

//
if ($_SERVER['HTTP_HOST'] == 'www2.okstudio.app') {
    $appEnv = 'dev';
} else {
    $appEnv = 'production';
}
define('app_env', $appEnv);
//
$gMyOrderStatus = [];
$gMyOrderStatus['new'] = '未出貨';
$gMyOrderStatus['shipped'] = '已出貨';
$gMyOrderStatus['cancel'] = '取消單';

/**
 * MOMO物流
 * unsendThirdQuery 用到
 */
$gMoDeliveries = [];
$gMoDeliveries[61] = '宅配通';
$gMoDeliveries[62] = '新竹';
$gMoDeliveries[63] = '宅急便';
$gMoDeliveries[65] = '..文件沒更新';


/**
 * Yahoo 變數
 */
class YahooConst
{
    const Token = "Supplier_10454";
    const SupplierId = "10454";
    const KeyValue = "aLuZHW3us4iWNs0C7YvbnzPiPH6NCmhaqDqRyZvNbmA=";
    const KeyIV = "JpVkbWmVcZdcjfQL4bravQ==";
    const SaltKey = "kzIFcX0aXdJuphj9ruQSBd4nVCz1WMvs";
    const KeyVersion = 4;
}

/**
 * MOMO變數
 */
class MOMOConst
{
    const ENTP_ID = "53617790"; // 統編
    const ENTY_CODE = "027410"; // 廠商編號
    const ENTY_PWD = "BB22356664"; // master密碼
    const OPT_BACK_NO = "416";

    public static function getLoginInfo() {
        $loginInfo = [];
        $loginInfo['entpID'] = self::ENTP_ID; // 統編
        $loginInfo['entpCode'] = self::ENTY_CODE; // 廠商編號
        $loginInfo['entpPwd'] = self::ENTY_PWD; // master 密碼
        $loginInfo['otpBackNo'] = self::OPT_BACK_NO;
        return $loginInfo;
    }
}

/**
 * Yahoo 建議使用他們的 time
 */
function getYahooTime() {
    $uri = "https://tw.ews.mall.yahooapis.com/stauth/v1/echo?Format=json";
    $fetch = new FetchHttp;
    $res = $fetch->httpPost($uri, $posts = []);
    $output = json_decode($res['output'], true);
    return $output['Response']['TimeStamp'] ?? '';
}


/**
 * 把 2025-10-10T00:00:00
 * 轉 2025-10-10 00:00:00
 */
function fDate($dt) {
    return str_replace('T', ' ', $dt);
}

function addApiLog($channel, $uri, $content, $getinfo = [])
{
    $inputs = [];
    $inputs['channel'] = $channel;
    $inputs['uri'] = $uri;
    $inputs['content'] = is_string($content) ? $content : json_encode($content);
    $inputs['getinfo'] = json_encode($getinfo);
    $inputs['created_at'] = date('Y-m-d H:i:s');

    $tmp = pdo_insert_sql('api_logs', $inputs);
    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
    $sth->execute($tmp['params']);
}

function addOpLog($params = [])
{
    $dirPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/log/'.date('y');
    if (! is_dir($dirPath)) {
        mkdir($dirPath);
    }
    $logPath = $dirPath.'/'.date('ymd').'.log';
    $txt = date('Y-m-d H:i:s').": ".$params['message']."\r\n";
    file_put_contents($logPath, $txt, FILE_APPEND);
}
function getOpLog($lines = 10)
{
    $dirPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/log/'.date('y');
    $logPath = $dirPath.'/'.date('ymd').'.log';
    if (! is_file($logPath)) {
        return [];
    }
    $lastLines = tail($logPath, $lines);
    return $lastLines;
}
function tail($filename, $lines = 10) {
    if (! is_file($filename)) {
        return [];
    }
    $f = fopen($filename, "r");
    $buffer = '';
    $chunkSize = 4096;
    $pos = -1;
    $lineCount = 0;

    fseek($f, 0, SEEK_END);
    $fileSize = ftell($f);

    while ($lineCount < $lines && -$pos < $fileSize) {
        $pos -= $chunkSize;
        if (-$pos > $fileSize) $pos = -$fileSize;
        fseek($f, $pos, SEEK_END);
        $chunk = fread($f, min($chunkSize, $fileSize));
        $buffer = $chunk . $buffer;
        $lineCount = substr_count($buffer, "\n");
    }

    fclose($f);

    $linesArray = explode("\n", trim($buffer));
    return array_slice($linesArray, -$lines);
}







/**
 * 
 * 
 * 
 * 
 * 
 */

/**
 * @param int $updQty 變更為這個庫存數量
 */
function mUpdQty($post, $updQty)
{
    $strSQL = "SELECT * FROM my_listings WHERE y_id = :y_id ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute(['y_id' => $post['y_id']]);
    $product = $sth->fetch();
    $extra = json_decode($product['m_extra'], true);

    $momoShopping = new MomoShopping;
    $mStocks = $momoShopping->getQty($product['m_code']);
    foreach ($mStocks as $i => $momo) {
        $mPart = $momo['entp_goods_no'];
        $mCode = $momo['goods_code'];
        $mStock = $momo['order_counsel_qty'];

        if ($momo['goods_code'] == $extra['goods_code']
            && $momo['goods_name'] == $extra['goods_name']
            && $momo['goodsdt_code'] == $extra['goodsdt_code']
            && $momo['goodsdt_info'] == $extra['goodsdt_info']) {

            if ($updQty == $mStock) {
                // 沒有變動
                break;
            }
            if ($updQty > $mStock) {
                // 假設 updQty 4, mQty 1, opQty 3
                $opQty = $updQty - $mStock;
            } elseif ($updQty < $mStock) {
                // 假設 updQty 1, mQty 3, opQty -2
                $opQty = ($mStock - $updQty) * -1;
            }
            $params = [];
            $params['goodsCode'] = $extra['goods_code'];
            $params['goodsName'] = $extra['goods_name'];
            $params['goodsdtCode'] = $extra['goodsdt_code'];
            $params['goodsdtInfo'] = $extra['goodsdt_info'];
            $params['orderCounselQty'] = $mStock;
            $params['addReduceQty'] = $opQty;
            momoUpdQty($params);
        }
    }
}



/**
 * momo 變更庫存數量
 */
function momoUpdQty($params)
{
    $fetch = new FetchHttp;
    $uri = 'https://scmapi.momoshop.com.tw';
    $apiUri = $uri.'/GoodsServlet.do'; // 商品加減量-加/減量申請

    $loginInfo = [];
    $loginInfo['entpID'] = '53617790'; // 統編
    $loginInfo['entpCode'] = '027410'; // 廠商編號
    $loginInfo['entpPwd'] = 'BB22356664'; // master 密碼
    $loginInfo['otpBackNo'] = '416';

    $arr = explode('/', $apiUri);
    $doAction = array_pop($arr);
    if ($doAction == 'GoodsServlet.do') {
        $doAction = 'changeGoodsQty';
    }

    $posts = [];
    $posts['doAction'] = $doAction;
    $posts['loginInfo'] = $loginInfo;
    //
    switch ($doAction) {
        case 'changeGoodsQty':
            // $params = [];
            // $params['goodsCode'] = '14431070';
            // $params['goodsName'] = '【TOD’S】Timeless 35mm 黑色x酒紅雙釦頭壓紋牛皮腰帶禮盒(腰帶 皮帶)';
            // $params['goodsdtCode'] = '001';
            // $params['goodsdtInfo'] = '-90';
            // $params['orderCounselQty'] = '9';
            // $params['addReduceQty'] = '-6';
            $params['baljuChgFlag'] = '';
            //
            $posts['sendInfoList'] = [];
            $posts['sendInfoList'][] = $params;
            break;
    }

    $headers = [];
    $headers['Content-Type'] = 'application/json';

    $extras = [];
    $extras['headers'] = $headers;

    $res = $fetch->httpPost($apiUri, json_encode($posts), $extras);
    $output = json_decode($res['output'], true);
    return $output;
}


function momo_UpdEntpPassword($str)
{
    if (app_env == 'production') {
        $updates = [];
        $updates['content'] = $str;
        $updates['updated_at'] = date('Y-m-d H:i:s');

        $where = [];
        $where['meta_key'] = 'momo';
        $where['meta_sub'] = 'api_entp_pwd';
        
        $tmp = pdo_update_sql('app_metas', $updates, false, false, $where);
        $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth->execute($tmp['params']);
        $cnt = $sth->rowCount();
        addOpLog(['message' => 'MOMO: 變更密碼']);
    } else {
        echo '<h4 style="color: red;">測試環境不允許變更</h4>';
        return ;
    }
}
function momo_GetEntpPassword()
{
    $strSQL = "SELECT * FROM app_metas WHERE meta_key = :meta_key AND meta_sub = :meta_sub ";
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute([
        'meta_key' => 'momo',
        'meta_sub' => 'api_entp_pwd',
    ]);
    $data = $sth->fetch();
    return $data['content'];
}