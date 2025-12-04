<?php

require_once('functions.php');

use App\Libraries\FetchHttp;

/**
 * Yahoo API 文件
 * https://tw.supplier.yahoo.com/docs/sp/api/endpoints/common/
 * 訂單相關
 * https://tw.supplier.yahoo.com/docs/scm/
 */


/**
 * Yahoo API 分 SCM 跟 Supplier Portal 
 * 在 API Key 這邊都是一樣的，只是 supplier Portal 那邊多了 cookie，所以你可以用之前 call supplier Portal 的簽章程式
 */
class YahooShopping
{
    public $cookie;
    public $cookieExpiredTime;
    public $wssid;

    /**
     * cookie
     * expired_time
     * wssid
     * 
     * order_date
     */
    public $meta;

    public $sample;

    public function __construct()
    {
        $this->sample = new YahooSample;
        $this->meta = $this->getMeta();
    }

    /**
     * 步驟 1.
     * 
     * Sign in 完成後會由 Response Set-Cookie Header 回傳 _sp Cookie
     * 其效期為 6 小時。
     * 存其本 API 其他各 Endpoint 都需於 Header 加上此 Cookie 欄位。
     */
    public function login()
    {
        if ($this->meta['expired_time'] < time() || $this->cookie == '') {
            $this->cookie = $this->sample->main();
            $this->cookieExpiredTime = (time() + 6 * 3600) - 60 * 10; // 提早10分鐘刷新
            //
            $this->meta['cookie'] = $this->cookie;
            $this->meta['expired_time'] = $this->cookieExpiredTime;
            $this->updMeta();
        } else {
            $this->cookie = $this->meta['cookie'];
            $this->cookieExpiredTime = $this->meta['expired_time'];
            $this->wssid = $this->meta['wssid'];
        }
        if (! isset($this->meta['order_date'])) {
            $this->meta['order_date'] = '2025-10-01';
        }
        //
        $uri = 'https://tw.supplier.yahoo.com/api/spa/v1/token';
        $headers = [];
        $headers[] = 'Cookie: '.$this->meta['cookie'];

        $fetch = new FetchHttp;
        $res = $fetch->httpGetYahoo($uri, $headers);
        $arr = json_decode($res['output'], true);
        $this->wssid = $arr['wssid'];
        $this->meta['wssid'] = $this->wssid;
    }


    /**
     * 查詢商品
     * @param integer y_id
     * @param integer offset 從哪一筆開始
     * @param integer limit 每頁幾筆，最多50，超過會噴錯
     */
    public function getProducts($params = [])
    {
        $uri = 'https://tw.supplier.yahoo.com/api/spa/v1/products';
        $quys = [];
        if (! empty($params['y_id'])) {
            $quys[] = 'id='.$params['y_id'];
        }
        if (! empty($params['y_part'])) {
            $quys[] = 'partNo='.$params['y_part'];
        }
        if (isset($params['offset'])) {
            $quys[] = 'offset='.$params['offset'];
        }
        if (isset($params['limit'])) {
            $quys[] = 'limit='.$params['limit'];
        }
        if ($quys) {
            $uri .= '?'.implode('&', $quys);
        }
        $uri .= '&fields=%2BlistingIdList'; // 要 encode
        // $uri .= '&fields=+listingIdList';
        
        $headers = [];
        $headers[] = 'Cookie: '.$this->cookie;
        $headers[] = 'X-YahooWSSID-Authorization: '.$this->wssid;
        
        $fetch = new FetchHttp;
        $res = $fetch->httpGetYahoo($uri, $headers);
        $getinfo = $res['getinfo'];
        if ($res['getinfo']['http_code'] == 400) {
            addOpLog(['message' => 'Yahoo: 錯誤:'.$res['output']]);
            return [];
        }
        addApiLog('yahoo', $uri, $res['output'], $getinfo);
        $output = json_decode($res['output'], true);
        return $output;
    }
    /**
     * 已取消訂單查詢
     * https://tw.supplier.yahoo.com/docs/scm/api/actions/homedelivery/getcanceledorders/
     * 
     * @param array OrderCode
     */
    public function getCanceledOrders($orderNos = [])
    {   
        $uris = [];
        // 已取消訂單查詢，直配
        $uris['home'] = "https://tw.scm.yahooapis.com/scmapi/api/HomeDelivery/GetCanceledOrders";
        // 已取消訂單查詢，店配（超商取貨）
        $uris['store'] = "https://tw.scm.yahooapis.com/scmapi/api/StoreDelivery/GetCanceledOrders";
        // 已取消訂單查詢，第三方（官方的物流宅配）
        $uris['third'] = "https://tw.scm.yahooapis.com/scmapi/api/ThirdPartyDelivery/GetCanceledOrders";

        $posts = [];
        $posts['OrderCode'] = $orderNos;

        foreach ($uris as $k => $uri) {
            $xx = $this->getHeaders($posts);
            $cipherText = $xx['cipherText'];
            $headers = $xx['headers'];
            $aesOpenSSL = $xx['aesOpenSSL'];
            
            $extras = [];
            $extras['headers'] = $headers;
            //
            $body = $cipherText;
            $fetch = new FetchHttp;
            $res = $fetch->httpPost($uri, $body, $extras);
            $output = $res['output'];
            $getinfo = $res['getinfo'];
            $decryptString = $aesOpenSSL->decryptString($output);
            echo "<pre>" .print_r($decryptString, true). "</pre>";
            addApiLog('yahoo', $uri, $output, $getinfo);
            if ($getinfo['http_code'] == 200) {
                $decryptString = $aesOpenSSL->decryptString($output);
                $output = json_decode($decryptString, true);
                echo "<pre>" .print_r($output, true). "</pre>";
                
            } else {
                addOpLog(['message' => 'Yahoo:已取消訂單查詢失敗']);
            }
        }
        return false;
    }
    /**
     * 待出貨訂單v1
     * https://tw.supplier.yahoo.com/docs/scm/api/actions/homedelivery/getpreparingorders/
     * $output = $yahoo->getPreparingOrders($params);
     * $cnt = $output['OrderCount'];
     * $orders = $output['Orders'];
     * 
     * @param datetime start_date Y-m-d
     * @param datetime end_date Y-m-d
     */
    public function getPreparingOrders($params = [])
    {
        // 三個物流訂單湊成一個
        $data = [];
        $data['TotalCount'] = 0;
        $data['TotalOrders'] = [];
        //
        $uris = [];
        // 待出貨訂單，直配
        $uris['home'] = "https://tw.scm.yahooapis.com/scmapi/api/HomeDelivery/GetPreparingOrders";
        // 待出貨訂單，店配（超商取貨）
        $uris['store'] = "https://tw.scm.yahooapis.com/scmapi/api/StoreDelivery/GetPreparingOrders";
        // 待出貨訂單，第三方（官方的物流宅配）
        $uris['third'] = "https://tw.scm.yahooapis.com/scmapi/api/ThirdPartyDelivery/GetPreparingOrders";

        $posts = [];
        $posts['TransferDateStart'] = $params['start_date'] ?? date('Y-m-d').'T00:00:00';
        $posts['TransferDateEnd'] = $params['end_date'] ?? date('Y-m-d').'T23:59:59';
        
        foreach ($uris as $k => $uri) {
            $xx = $this->getHeaders($posts);
            $cipherText = $xx['cipherText'];
            $headers = $xx['headers'];
            $aesOpenSSL = $xx['aesOpenSSL'];
            
            $extras = [];
            $extras['headers'] = $headers;
            //
            $body = $cipherText;
            $fetch = new FetchHttp;
            $res = $fetch->httpPost($uri, $body, $extras);
            $output = $res['output'];
            $getinfo = $res['getinfo'];
            addApiLog('yahoo', $uri, $output, $getinfo);
            if ($getinfo['http_code'] == 200) {
                $decryptString = $aesOpenSSL->decryptString($output);
                $output = json_decode($decryptString, true);
                $data['TotalCount'] += $output['OrderCount'];
                $data['TotalOrders'][$k] = $output['Orders'];
                
            } else {
                addOpLog(['message' => 'Yahoo:抓取未出貨訂單失敗']);
            }
        }
        if ($data['TotalCount']) {
            return $data;
        }
        return [];
    }

    private function getHeaders($posts)
    {
        $plainText = json_encode($posts);
        $sample = new YahooSample;
        $result = $sample->getSignature($plainText);

        $token = $sample->token;
        $signature = $result['signature'];
        $timestamp = $result['timestamp'];
        $supplierId = $sample->supplierId;
        $keyVersion = 1;
        $cipherText = $result['cipherText'];
        //
        $shareSecretKey = $sample->shareSecretKey;
        $shareSecretIV = $sample->shareSecretIv;
        $aesOpenSSL = new AES_OpenSSL($shareSecretKey, $shareSecretIV);
        //
        $headers = [];
        $headers['Accept'] = 'application/json';
        $headers['Content-Type'] = 'application/json';
        $headers['api-token'] = $token;
        $headers['api-signature'] = $signature;
        $headers['api-timestamp'] = $timestamp;
        $headers['api-keyversion'] = $keyVersion;
        $headers['api-supplierid'] = $supplierId;
        return [
            'aesOpenSSL' => $aesOpenSSL,
            'cipherText' => $cipherText,
            'headers' => $headers,
        ];
    }
    /**
     * 已出貨訂單v1
     * $output = $yahoo->getShippingOrders($params);
     * $cnt = $output['OrderCount'];
     * $orders = $output['Orders'];
     * 
     * @param datetime start_date Y-m-d
     * @param datetime end_date Y-m-d
     */
    public function getShippingOrders($params = [])
    {
        // 三個物流訂單湊成一個
        $data = [];
        $data['TotalCount'] = 0;
        $data['TotalOrders'] = [];
        //
        $uris = [];
        // 已出貨訂單，直配作業, 結果 Cache 10分鐘
        $uris[] = "https://tw.scm.yahooapis.com/scmapi/api/HomeDelivery/GetShippingOrders";
        // 已出貨訂單，店配
        $uris[] = "https://tw.scm.yahooapis.com/scmapi/api/StoreDelivery/GetShippingOrders";
        // 已出貨訂單，三方作業, 結果 Cache 10分鐘
        $uris[] = "https://tw.scm.yahooapis.com/scmapi/api/ThirdPartyDelivery/GetShippingOrders";
        // 已出貨訂單，集宅配作業, 結果Cache 10分鐘
        $uris[] = 'https://tw.scm.yahooapis.com/scmapi/api/ExpressDelivery/GetShippingOrders';
        //
        $posts = [];
        $posts['TransferDateStart'] = $params['start_date'] ?? date('Y-m-d').'T00:00:00';
        $posts['TransferDateEnd'] = $params['end_date'] ?? date('Y-m-d').'T23:59:59';
        
        foreach ($uris as $k => $uri) {
            $xx = $this->getHeaders($posts);
            $cipherText = $xx['cipherText'];
            $headers = $xx['headers'];
            $aesOpenSSL = $xx['aesOpenSSL'];
            
            $extras = [];
            $extras['headers'] = $headers;
            //
            $body = $cipherText;
            $fetch = new FetchHttp;
            $res = $fetch->httpPost($uri, $body, $extras);
            $output = $res['output'];
            $getinfo = $res['getinfo'];
            addApiLog('yahoo', $uri, $output, $getinfo);
            if ($getinfo['http_code'] == 200) {
                $decryptString = $aesOpenSSL->decryptString($output);
                $output = json_decode($decryptString, true);
                $data['TotalCount'] += $output['OrderCount'];
                $data['TotalOrders'][$k] = $output['Orders'];
                
            } else {
                addOpLog(['message' => 'Yahoo:抓取已出貨訂單失敗']);
            }
        }
        return $data;



        // $plainText = json_encode($posts);
        // $sample = new YahooSample;
        // $result = $sample->getSignature($plainText);

        // $token = $sample->token;
        // $signature = $result['signature'];
        // $timestamp = $result['timestamp'];
        // $supplierId = $sample->supplierId;
        // $keyVersion = 1;
        // $cipherText = $result['cipherText'];
        // //
        // $shareSecretKey = $sample->shareSecretKey;
        // $shareSecretIV = $sample->shareSecretIv;
        // $aesOpenSSL = new AES_OpenSSL($shareSecretKey, $shareSecretIV);
        // //
        // $headers[] = 'Accept: application/json';
        // $headers[] = 'Content-Type: application/json';
        // $headers[] = "api-token: $token";
        // $headers[] = "api-signature: $signature";
        // $headers[] = "api-timestamp: $timestamp";
        // $headers[] = "api-keyversion: $keyVersion";
        // $headers[] = "api-supplierid: $supplierId";
        
        // //
        // $body = $cipherText;
        // $ch = curl_init();
        // curl_setopt($ch, CURLOPT_URL, $uri);
        // curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        // curl_setopt($ch, CURLOPT_POST, true);
        // curl_setopt($ch, CURLOPT_HEADER, false);
        // $response = curl_exec($ch);
        // $getinfo = curl_getinfo($ch);
        // curl_close($ch);
        // addApiLog('yahoo', $uri, $response, $getinfo);
        // if ($getinfo['http_code'] == 200) {
        //     $decryptString = $aesOpenSSL->decryptString($response);
        //     $output = json_decode($decryptString, true);
        //     return $output;
        // }
        // return [];
    }

    public function zz()
    {
        $posts = [];
        $posts['TransferDateStart'] = $params['start_date'] ?? date('Y-m-d', strtotime('-30 day')).'T00:00:00';
        $posts['TransferDateEnd'] = $params['end_date'] ?? date('Y-m-d').'T23:59:59';
        
        $plainText = json_encode($posts);
        $sample = new YahooSample;
        $result = $sample->getSignature($plainText);

        $token = $sample->token;
        $signature = $result['signature'];
        $timestamp = $result['timestamp'];
        $supplierId = $sample->supplierId;
        $keyVersion = 1;
        $cipherText = $result['cipherText'];
        //
        $shareSecretKey = $sample->shareSecretKey;
        $shareSecretIV = $sample->shareSecretIv;
        $aesOpenSSL = new AES_OpenSSL($shareSecretKey, $shareSecretIV);
        //
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Type: application/json';
        $headers[] = "api-token: $token";
        $headers[] = "api-signature: $signature";
        $headers[] = "api-timestamp: $timestamp";
        $headers[] = "api-keyversion: $keyVersion";
        $headers[] = "api-supplierid: $supplierId";
        // 未付款訂單，直配作業, 結果 Cache 10分鐘
        // $uri = 'https://tw.scm.yahooapis.com/scmapi/api/HomeDelivery/GetUnpaidOrders';
        // 待出貨訂單，直配
        // $uri = 'https://tw.scm.yahooapis.com/scmapi/api/HomeDelivery/GetPreparingOrders';
        // 待出貨訂單，店配（超商取貨）
        // $uri = 'https://tw.scm.yahooapis.com/scmapi/api/StoreDelivery/GetPreparingOrders';
        // 已出貨訂單，直配作業, 結果 Cache 10分鐘
        // $uri = "https://tw.scm.yahooapis.com/scmapi/api/HomeDelivery/GetShippingOrders";
        // 未付款訂單，第三方
        // $uri = 'https://tw.scm.yahooapis.com/scmapi/api/ThirdPartyDelivery/GetUnpaidOrders';
        // 待出貨訂單，第三方（官方的物流宅配）
        $uri = 'https://tw.scm.yahooapis.com/scmapi/api/ThirdPartyDelivery/GetPreparingOrders';
        // 已出貨訂單，店配
        // $uri = "https://tw.scm.yahooapis.com/scmapi/api/StoreDelivery/GetShippingOrders";
        // 已出貨訂單，三方作業, 結果 Cache 10分鐘
        // $uri = "https://tw.scm.yahooapis.com/scmapi/api/ThirdPartyDelivery/GetShippingOrders";
        //
        $body = $cipherText;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $response = curl_exec($ch);
        $getinfo = curl_getinfo($ch);
        curl_close($ch);
        addApiLog('yahoo', $uri, $response, $getinfo);
        if ($getinfo['http_code'] == 200) {
            $decryptString = $aesOpenSSL->decryptString($response);
            $output = json_decode($decryptString, true);
            return $output;
        }
        return [];
    }
    
    /**
     * https://tw.supplier.yahoo.com/docs/sp/api/endpoints/listing/
     */
    public function getListings($params = [])
    {
        $uri = 'https://tw.supplier.yahoo.com/api/spa/v1/listings';
        $quys = [];
        if (! empty($params['id'])) { // 賣場編號
            $quys[] = 'id='.$params['id'];
        }
        if (isset($params['offset'])) {
            $quys[] = 'offset='.$params['offset'];
        }
        $quys[] = 'limit=50'; // 最多50
        if ($quys) {
            $uri .= '?'.implode('&', $quys);
        }
        $headers = [];
        $headers[] = 'Cookie: '.$this->cookie;
        $headers[] = 'X-YahooWSSID-Authorization: '.$this->wssid;
        
        $fetch = new FetchHttp;
        $res = $fetch->httpGetYahoo($uri, $headers);
        $output = json_decode($res['output'], true);
        return $output;
    }

    public function getMeta()
    {
        $metaPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/y_meta.json';
        if (is_file($metaPath)) {
            $meta = file_get_contents($metaPath);
            $meta = json_decode($meta, true);
        } else {
            $meta = [];
            $meta['offset'] = 0;
            $meta['limit'] = 2; // 最大 50，超過噴錯
            $meta['cookie'] = '';
            $meta['wssid'] = '';
            $meta['expired_time'] = '';
            file_put_contents($metaPath, json_encode($meta));
        }
        return $meta;
    }

    public function updMeta()
    {
        $metaPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/y_meta.json';
        file_put_contents($metaPath, json_encode($this->meta));
    }



    /**
     * 更新庫存, 庫存數+-量
     * https://tw.supplier.yahoo.com/docs/scm/api/actions/stock/updateqty/
     */
    public function Stock_UpdateQty($params = [])
    {
        if (app_env == 'production') {
            
        } else {
            return ;
        }
        $params['ProductId'] = $params['ProductId']; // y_id
        $params['Qty'] = $params['Qty']; // +1, -1
        if (! $params['ProductId']) {
            // echo "無異動 -198<br>";
            return ;
        }
        
        $plainText = json_encode($params);
        $sample = new YahooSample;
        $result = $sample->getSignature($plainText);

        $token = $sample->token;
        $signature = $result['signature'];
        $timestamp = $result['timestamp'];
        $supplierId = $sample->supplierId;
        $keyVersion = 1;
        $cipherText = $result['cipherText'];
        //
        $shareSecretKey = $sample->shareSecretKey;
        $shareSecretIV = $sample->shareSecretIv;
        $aesOpenSSL = new AES_OpenSSL($shareSecretKey, $shareSecretIV);
        //
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Type: application/json';
        $headers[] = "api-token: $token";
        $headers[] = "api-signature: $signature";
        $headers[] = "api-timestamp: $timestamp";
        $headers[] = "api-keyversion: $keyVersion";
        $headers[] = "api-supplierid: $supplierId";
        // 更新庫存
        $uri = "https://tw.scm.yahooapis.com/scmapi/api/GdStock/UpdateQty";
        //
        $body = $cipherText;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $response = curl_exec($ch);
        $getinfo = curl_getinfo($ch);
        curl_close($ch);
        addApiLog('yahoo', $uri, $response, $getinfo);
        if ($getinfo['http_code'] == 200) {
            $decryptString = $aesOpenSSL->decryptString($response);
            $output = json_decode($decryptString, true);
            return $output;
        }
        return [];
    }


    /**
     * 查詢庫存
     * https://tw.supplier.yahoo.com/docs/scm/api/actions/stock/getqty/
     */
    public function Stock_GetQty($ProductId = '')
    {
        $params = [];
        $params['ProductId'] = $ProductId;
        if (! $params['ProductId']) {
            // echo "無異動 -140<br>";
            return ;
        }
        
        $plainText = json_encode($params);
        $sample = new YahooSample;
        $result = $sample->getSignature($plainText);

        $token = $sample->token;
        $signature = $result['signature'];
        $timestamp = $result['timestamp'];
        $supplierId = $sample->supplierId;
        $keyVersion = 1;
        $cipherText = $result['cipherText'];
        //
        $shareSecretKey = $sample->shareSecretKey;
        $shareSecretIV = $sample->shareSecretIv;
        $aesOpenSSL = new AES_OpenSSL($shareSecretKey, $shareSecretIV);
        //
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Type: application/json';
        $headers[] = "api-token: $token";
        $headers[] = "api-signature: $signature";
        $headers[] = "api-timestamp: $timestamp";
        $headers[] = "api-keyversion: $keyVersion";
        $headers[] = "api-supplierid: $supplierId";
        // 更新庫存
        $uri = "https://tw.scm.yahooapis.com/scmapi/api/GdStock/GetQty";
        //
        $body = $cipherText;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $response = curl_exec($ch);
        $getinfo = curl_getinfo($ch);
        curl_close($ch);
        addApiLog('yahoo', $uri, $response, $getinfo);
        if ($getinfo['http_code'] == 200) {
            $decryptString = $aesOpenSSL->decryptString($response);
            $output = json_decode($decryptString, true);
            return $output['Qty'] ?? 0;
        }
        return [];
    }
}


/**
 * 直接 API 文件範例，取得簽章用
 */
class YahooSample
{
    public $shareSecretKey = 'aLuZHW3us4iWNs0C7YvbnzPiPH6NCmhaqDqRyZvNbmA=';
    public $shareSecretIv = 'JpVkbWmVcZdcjfQL4bravQ==';
    public $saltKey = 'kzIFcX0aXdJuphj9ruQSBd4nVCz1WMvs';
    public $apiVersion = '1';
    public $token = 'Supplier_10454';
    public $supplierId = '10454';

    /**
     * 這段跟 getCookie 前面一樣
     * @param string $messageBody json_encode($params)
     */
    public function getSignature($messageBody)
    {
        $timestamp = time();

        $aes = new AES($this->shareSecretKey, $this->shareSecretIv);
        $cipherText = $aes->encrypt($messageBody);

        $hasher = new HMac($this->shareSecretKey);
        $signature = $hasher->sha512(sprintf("%s%s%s%s", $timestamp, $this->token, $this->saltKey, $cipherText));

        $headers = [
            "Content-Type: application/json; charset=utf-8",
            "api-token: ".$this->token,
            "api-signature: ".$signature,
            "api-timestamp: ".$timestamp,
            "api-keyversion: ".$this->apiVersion,
        ];
        return [
            'headers' => $headers,
            'cipherText' => $cipherText,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];
    }

    public function getCookie($uri, $messageBody)
    {
        $output = $this->fetchPost($uri, $messageBody);
        $res = $output['res'];
        $getinfo = $output['getinfo'];

        if ($getinfo['http_code'] == 204) {
            preg_match_all('/^Set-Cookie:\s(?\'cookie\'_sp=.+)$/mi', $res, $matches);
            return trim($matches['cookie'][0]);
        } else {
            addOpLog(['message' => 'Yahoo: 連線失敗 #460']);
            throw new Exception("Failed to obtain the SCM cookie");
        }
    }

    public function main()
    {
        $uri = "https://tw.supplier.yahoo.com:443/api/spa/v1/signIn";
        $requestContent = '{"supplierId":"' .$this->supplierId. '"}';
        $cookie = $this->getCookie($uri, $requestContent);
        return $cookie;
    }

    public function fetchPost($uri, $messageBody)
    {
        $res = $this->getSignature($messageBody);
        $cipherText = $res['cipherText'];
        $headers = $res['headers'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uri);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $cipherText);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $res = curl_exec($ch);
        $getinfo = curl_getinfo($ch);
        curl_close($ch);
        return [
            'res' => $res,
            'getinfo' => $getinfo,
        ];
    }
}


/**
 * 用於 YahooShopping 撈取後
 * 整理 Yahoo欄位
 */
class MyYahoo 
{
    /**
     * 跟MOMO配對
     */
    public function isMatchByGoodsdtInfo($yProducts, $goodsdtInfo)
    {
        $tmp_match_method = '';
        $isUpd = false;
        $yMatches = [];
        foreach ($yProducts as $ii => $yProd) {
            unset($yProd['images']);
            unset($yProd['shipType']);
            unset($yProd['structuredData']);

            if (! $this->isValidPart($yProd['partNo'])) {
                continue;
            }

            $yId = $yProd['id'];
            $yName = strtoupper($yProd['name']);
            $yMatchStr = $this->getMatchStr($yProd);
            $yStock = $yProd['stock'];
            $yAttr1 = $this->getAttr($yProd);
            $yAttr2 = $this->getSpec($yProd);

            $yMatches[] = $yMatchStr;

            // 是否配對到 規則 1/2/3/4
            if ($goodsdtInfo == $yMatchStr) {
                $isUpd = true;
                $tmp_match_method = 'A';
            }

            if (! $isUpd) {
                // 注意結尾可能是 女側H棕尾 38 / 女側H棕尾 38.5
                //
                $goodsdt_info = $goodsdtInfo == '無' ? '' : $goodsdtInfo;
                $goodsdt_info = preg_quote($goodsdt_info, '/');


                // Yahoo沒有規格欄位，用名稱判斷
                // yId: 38064794, 例如: GOYARD St. Louis PM 中款 高雅德防水圖騰帆布肩背托特包/附收納袋-多色可選-深藍
                // -規格
                $tmp = '-'.$goodsdt_info;
                preg_match('/' .$tmp. '$/iu', $yName, $matches);
                if (! empty($matches[0])) {
                    $isUpd = true;
                    $tmp_match_method = 'B';
                }

                if (! $isUpd) {
                    // MOMO.$goodsdt_info 格式有點不一樣
                    // 例如: 2440266-07, MOMO.goodsdt_info = 咖啡色麂皮款-男鞋 9.5, 但是 Yahoo.[name] => HOGAN Cool H 超熱賣麂皮/全皮休閒鞋 德訓鞋-男女款可選-咖啡色麂皮款-男鞋-9.5
                    $tmp = '-'.str_replace(' ', '-', $goodsdt_info);
                    preg_match('/' .$tmp. '$/iu', $yName, $matches);
                    if (! empty($matches[0])) {
                        $isUpd = true;
                        $tmp_match_method = 'C';
                    }
                }

                if (! $isUpd) {
                    // MOMO.$goodsdt_info 格式有點不一樣
                    // 例如: 2220459-B3, MOMO.goodsdt_info = -105/105, 但是 Yahoo.[name] => LOEWE 羅威 Anagram 40mm 雙面用金釦撞色小牛皮腰帶(棕褐x黑色)-105
                    $tmp = str_replace(' ', '-', $goodsdt_info);
                    preg_match('/' .$tmp. '$/iu', $yName, $matches);
                    if (! empty($matches[0])) {
                        $isUpd = true;
                        $tmp_match_method = 'D';
                    }
                }
                
                if (! $isUpd) {
                    // MOMO.$goodsdt_info 格式有點不一樣, goodsdt_info 有空格又有-號
                    // 例如: 2530063-03, MOMO.goodsdt_info = 杏色牛皮款-女鞋 -37, 但是 Yahoo.[name] => LOEWE 羅威 Anagram 40mm 雙面用金釦撞色小牛皮腰帶(棕褐x黑色)-105
                    $tmp = str_replace(' -', '-', $goodsdt_info);
                    preg_match('/' .$tmp. '$/iu', $yName, $matches);
                    if (! empty($matches[0])) {
                        $isUpd = true;
                        $tmp_match_method = 'E';
                    }
                }


                if (! $isUpd) {
                    // m: 灰藍麂皮款-女鞋 -37
                    // y: 灰藍麂皮款-女鞋 37

                    $tmpYahooStr1 = $yAttr1.'-'.$yAttr2;
                    $tmpYahooStr2 = $yAttr1.' -'.$yAttr2;
                    if ($goodsdtInfo == $tmpYahooStr1) {
                        $isUpd = true;
                        $tmp_match_method = 'F';
                    } elseif ($goodsdtInfo == $tmpYahooStr2) {
                        $isUpd = true;
                        $tmp_match_method = 'G';
                    }
                }
            }
        }
        return [
            'is_match' => $isUpd,
            'tmp_match_method' => $tmp_match_method,
            'product' => $isUpd ? $yProd : [],
            'yMatches' => $yMatches,
        ];
    }
    /**
     * 檢查料號格式 2240026-B3, 2240036-02
     * 有些不是正規料號, 有些會帶品牌, 有些空白, 有些帶 -
     */
    public function isValidPart($part = '')
    {
        preg_match('/^([0-9]+\-[0-9a-zA-Z]{2})$/', $part, $matches);
        return empty($matches) ? false : true;
    }

    public function getBrand($brand = '')
    {
        $brand = strtoupper($brand);
        return $brand;
    }
    /**
     * 順序不能異動
     */
    public function getMatchStr($product)
    {
        $arr = [];
        $arr[0] = $this->getAttr($product);
        $arr[1] = $this->getSpec($product);
        //
        $str = implode(' ', $arr);
        $str = trim($str);
        return $str;
    }
    public function getAttr($product)
    {
        $attr = $product['parentSpec']['selectedValue'] ?? '';
        $attr = strtoupper($attr);
        return $attr;
    }
    public function getSpec($product)
    {
        $spec = $product['spec']['selectedValue'] ?? '';
        $spec = strtoupper($spec);
        return $spec;
    }
    public function getAttr1($product)
    {
        $attr = $product['parentSpec']['selectedValue'] ?? '';
        $attr = strtoupper($attr);
        return $attr;
    }
    public function getAttr2($product)
    {
        $attr2 = $product['spec']['selectedValue'] ?? '';
        $attr2 = strtoupper($attr2);
        return $attr2;
    }
    
    /**
     * yahoo->getProducts() 取得的商品名 name（訂單跟商品api取得的名稱不同）
     * 前面會帶品牌，後面會帶規格，需過濾
     */
    public function getName($product)
    {
        $name = strtoupper($product['name']);
        $spec = $this->getSpec($product);
        if ($spec != '') {
            $spec = str_replace('/', '\/', $spec);
            preg_match('/([\W\w]+)-(' .$spec. ')/', $name, $matches);
            $name = $matches[1] ?? $name;
        }
        return $name;
    }

    /**
     * 並從賣場API撈出 spec.selectedValue
     * * 注意！用這個 myYahoo->getById($yId) 有可能回傳空陣列, 當賣場已下架的時候
     */
    public function getById($oYahooShopping, $yId)
    {
        $remotes = $oYahooShopping->getProducts(['y_id' => $yId]);
        $remote = $remotes['products'][0] ?? [];
        if (! $remote) {
            addOpLog(['message' => "Yahoo#找不到".$yId]);
            exit;
        }
        unset($remote['images']);
        unset($remote['shipType']);
        unset($remote['structuredData']);
        $remote['_stock'] = $remote['availableCount'] ?? 0;
        $remote['_match_str'] = $this->getMatchStr($remote);
        $remote['_attr1'] = $this->getAttr1($remote);
        $remote['_attr2'] = $this->getAttr2($remote);
        //
        $listingIds = $remote['listingIdList'] ?? [];
        foreach ($listingIds as $listingId) {
            $res = $oYahooShopping->getListings([
                'id' => $listingId,
            ]);
            $listings = $res['listings'] ?? [];
            if (! $listings) {
                unset($remotes[$i]);
                continue;
            }
            foreach ($listings as $ii => $vv) {
                unset($vv['allowedDiscounts']);
                unset($vv['shortDescription']);
                unset($vv['skuDetail']);
                // 賣場結束時間 2023-11-29T01:28:31Z, 下架時間+1天，才不會判對不到商品
                if ($vv['endTs'] && time() > (strtotime($vv['endTs']) + 86400)) {
                    addOpLog(['message' => "Yahoo: 商品編號".$yId." 賣場編號 ".$listingId.' 已下架, 下架時間 '.$vv['endTs']]);
                    // 已下架
                    return [];
                }
                if ($vv['status'] != 'normal'
                    && $vv['status'] != 'outOfStock'
                    && $vv['status'] != 'preImpression') {
                    addOpLog(['message' => "Yahoo: 商品編號".$yId." 賣場編號 ".$listingId.' 沒有上架 '.$vv['status']]);
                    // 已下架
                    return [];
                }
                $models = $vv['models'] ?? [];
                foreach ($models as $i3 => $v3) {
                    if (! isset($v3['items'])) {
                        $sku = $v3['sku'];
                        $yStock = $v3['availableCount'];
                        $matchStr = $this->getMatchStr($v3);
                        $attr1 = $this->getAttr1($v3);
                        $attr2 = $this->getAttr2($v3);
                        if ($sku == $yId) {
                            $remote['_stock'] = $yStock;
                            $remote['_match_str'] = $matchStr;
                            $remote['_attr1'] = $attr1;
                            $remote['_attr2'] = $attr2;
                            $remote['_end_ts'] = $vv['endTs'];
                            $this->putData($yId, $remote);
                            return $remote;
                        }
                    } else {
                        foreach ($v3['items'] as $i4 => $v4) {
                            $sku = $v4['sku'];
                            $yStock = $v4['availableCount'];
                            $matchStr = $this->getMatchStr($v4);
                            $attr1 = $this->getAttr1($v4);
                            $attr2 = $this->getAttr2($v4);
                            if ($sku == $yId) {
                                $remote['_stock'] = $yStock;
                                $remote['_match_str'] = $matchStr;
                                $remote['_attr1'] = $attr1;
                                $remote['_attr2'] = $attr2;
                                $remote['_end_ts'] = $vv['endTs'];
                                $this->putData($yId, $remote);
                                return $remote;
                            }
                        }
                    }
                }
            }
        }
        $this->putData($yId, $remote);
        return $remote;
    }
    
    /**
     * 只查詢今天，避免資料更新了
     */
    private function getData($yId)
    {
        $dataPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/log/y_product/'.$yId.'.json';
        if (is_file($dataPath)) {
            $mtime = filemtime($dataPath);
            $mdate = date('Y-m-d', $mtime);
            if ($mdate != date('Y-m-d')) {
                unlink($dataPath);
            } else {
                $content = file_get_contents($dataPath);
                $data = json_decode($content, true);
            }
        }
        return $data ?? [];
    }
    /**
     * 只查詢今天，避免資料更新了
     */
    private function putData($yId, $remote)
    {
        $dataPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/log/y_product/'.$yId.'.json';
        if (is_file($dataPath)) {
            $mtime = filemtime($dataPath);
            $mdate = date('Y-m-d', $mtime);
            if ($mdate != date('Y-m-d')) {
                file_put_contents($dataPath, json_encode($remote));
            }
        } else {
            file_put_contents($dataPath, json_encode($remote));
        }
    }
    /**
     * 直接過濾不用的欄位
     */
    public function getByPart($oYahooShopping, $part)
    {
        $res = $oYahooShopping->getProducts(['y_part' => $part]);
        $remotes = $res['products'] ?? [];
        if (! $remotes) {
            addOpLog(['message' => "Yahoo: 料號不存在 ".$part]);
            return [];
        }
        foreach ($remotes as $i => $v) {
            unset($remotes[$i]['images']);
            unset($remotes[$i]['shipType']);
            unset($remotes[$i]['structuredData']);

            $yId = $remotes[$i]['id'];
            $remotes[$i]['_stock'] = $remotes[$i]['availableCount'] ?? 0;
            $remotes[$i]['_match_str'] = $this->getMatchStr($remotes[$i]);
            $remotes[$i]['_attr1'] = $this->getAttr1($remotes[$i]);
            $remotes[$i]['_attr2'] = $this->getAttr2($remotes[$i]);
            $remotes[$i]['_end_ts'] = '';
            $remotes[$i]['_error'] = '';
            // 賣場
            $listingIds = $remotes[$i]['listingIdList'] ?? [];
            if (! $listingIds) {
                unset($remotes[$i]);
                continue;
            }
            foreach ($listingIds as $listingId) {                    
                $res = $oYahooShopping->getListings([
                    'id' => $listingId,
                ]);
                $listings = $res['listings'] ?? [];
                if (! $listings) {
                    unset($remotes[$i]);
                    continue;
                }
                foreach ($listings as $iii => $vvv) {
                    unset($vvv['allowedDiscounts']);
                    unset($vvv['shortDescription']);
                    unset($vvv['skuDetail']);
                    // 賣場結束時間 2023-11-29T01:28:31Z
                    if ($vvv['endTs'] && time() > strtotime($vvv['endTs'])) {
                        // 已下架
                        unset($remotes[$i]);
                        continue;
                    }
                    if ($vvv['status'] != 'normal'
                        && $vvv['status'] != 'outOfStock'
                        && $vvv['status'] != 'preImpression') {
                        // 已下架
                        unset($remotes[$i]);
                        continue;
                    }
                    $models = $vvv['models'] ?? [];
                    foreach ($models as $i4 => $v4) {
                        if (! isset($v4['items'])) {
                            $sku = $v4['sku'];
                            $yStock = $v4['availableCount'];
                            $matchStr = $this->getMatchStr($v4);
                            $attr1 = $this->getAttr1($v4);
                            $attr2 = $this->getAttr2($v4);
                            if ($sku == $yId) {
                                $remotes[$i]['_stock'] = $yStock;
                                $remotes[$i]['_match_str'] = $matchStr;
                                $remotes[$i]['_attr1'] = $attr1;
                                $remotes[$i]['_attr2'] = $attr2;
                                $remotes[$i]['_end_ts'] = $vvv['endTs'];
                                $remotes[$i]['_lv'] = 'v4';
                            }
                        } else {
                            foreach ($v4['items'] as $i5 => $v5) {
                                $sku = $v5['sku'];
                                $yStock = $v5['availableCount'];
                                $matchStr = $this->getMatchStr($v5);
                                $attr1 = $this->getAttr1($v5);
                                $attr2 = $this->getAttr2($v5);
                                if ($sku == $yId) {
                                    $remotes[$i]['_stock'] = $yStock;
                                    $remotes[$i]['_match_str'] = $matchStr;
                                    $remotes[$i]['_attr1'] = $attr1;
                                    $remotes[$i]['_attr2'] = $attr2;
                                    $remotes[$i]['_end_ts'] = $vvv['endTs'];
                                    $remotes[$i]['_lv'] = 'v5';
                                }
                            }
                        }
                    }
                }
            }
        }
        return $remotes;
    }

    /**
     * Yahoo 商品是否存在
     */
    function getMyProductById($yId)
    {
        $strSQL = "SELECT * FROM my_listings WHERE y_id = :y_id ";
        $sth = $GLOBALS['dbh']->prepare($strSQL);
        $sth->execute([
            'y_id' => $yId,
        ]);
        $exist = $sth->fetch();
        return $exist;
    }

    /**
     * 由 $yahoo->getProducts() 撈出單筆資料
     * YahooAddProduct
     */
    public function addMyProduct($vvv, $orderDate = false)
    {
        $dt = date('Y-m-d H:i:s');

        $brand = $this->getBrand($vvv['brand']);
        $name = $this->getName($vvv);
        $inputs = [
            'y_id' => $vvv['id'],
            'y_sku' => $vvv['id'],
            'y_part' => $vvv['partNo'],
            'y_brand' => $brand,
            'y_name' => $name,
            'y_spec' => $this->getSpec($vvv),
            'y_attr' => $this->getAttr($vvv),
            'y_price' => $vvv['msrp'],
            'y_stock' => $vvv['availableCount'] ?? 0,
            'y_match_str' => $this->getMatchStr($vvv),
            'y_extra' => json_encode($vvv, JSON_UNESCAPED_UNICODE),
            'y_created_at' => $dt,
            'y_updated_at' => $dt,
        ];
        if ($orderDate) {
            $inputs['y_order_date'] = $orderDate;
        }
        $tmp = pdo_insert_sql('my_listings', $inputs);
        $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth->execute($tmp['params']);
        $cnt = $sth->rowCount();
        return $cnt;
    }
    public function updMyProduct($vvv, $orderDate = false)
    {
        $dt = date('Y-m-d H:i:s');

        $brand = $this->getBrand($vvv['brand']);
        $name = $this->getName($vvv);
        $inputs = [
            'y_brand' => $brand,
            'y_name' => $name,
            'y_spec' => $this->getSpec($vvv),
            'y_attr' => $this->getAttr($vvv),
            'y_price' => $vvv['msrp'],
            'y_stock' => $vvv['availableCount'] ?? 0,
            'y_match_str' => $this->getMatchStr($vvv),
            'y_updated_at' => $dt,
            'y_extra' => json_encode($vvv, JSON_UNESCAPED_UNICODE),
        ];
        if ($orderDate) {
            $inputs['y_order_date'] = $orderDate;
        }
        $tmp = pdo_update_sql('my_listings', $inputs, $vvv['id'], 'y_id');
        $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
        $sth->execute($tmp['params']);
        $cnt = $sth->rowCount();
        return $cnt;
    }

    /**
     * 用momo得出y_id
     */
    public function getYIdByMomoGoodsCode($part, $goods_code, $goodsdt_info = '')
    {
        $yahooShopping = new YahooShopping;
        $yahooShopping->login();
        $myYahoo = new MyYahoo;
        $myMomo = new MyMomo;

        $mMatchStr = $myMomo->getMatchStr($goodsdt_info);
        $mAttr2 = $myMomo->getAttr2($goodsdt_info);
        //
        $remotes = $this->getByPart($yahooShopping, $part);
        foreach ($remotes as $i => $v) {
            $yId = $v['id'];
            $rPart = $v['partNo'];
            $matchStr = $v['_match_str'];
            $attr1 = $v['_attr1'];
            $attr2 = $v['_attr2'];
            if ($part == $rPart && $mAttr2 == $attr2) {
                $yName = $v['name'];
                $yStock = $v['_stock'] ?? 0;
                return [
                    'y_id' => $yId,
                    'y_name' => $yName,
                    'y_stock' => $yStock,
                    'y_match_str' => $matchStr,
                    'y_attr1' => $attr1,
                    'y_attr2' => $attr2,
                ];
            }
        }
        return [];
    }
}