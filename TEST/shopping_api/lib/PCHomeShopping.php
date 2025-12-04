<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

use App\Libraries\FetchHttp;

/**
 * 需申請IP白名單
 * https://developers.pchome.tw/#b0c41eb0-f446-47a2-bb93-5f4fb64f4445
 * 
 * 商品前台網址
 * https://24h.pchome.com.tw/prod/DICKGC-A900IYHWM
 */
class PCHomeShopping
{
    public $merchantId;

    public function __construct()
    {
        $this->merchantId = '39779';
    }

    /**
     * GET 1.2.9 查訂單明細列表：用轉單日期
     * @param int $limit 測試可到 112, default: 20
     */
    public function getOrders($params = [])
    {
        $params['start_date'] = date('Y/m/d', strtotime($params['start_date']));
        $params['end_date'] = date('Y/m/d', strtotime($params['end_date']));
        $params['offset'] = isset($params['offset']) ? $params['offset'] : 0;
        $params['limit'] = $params['limit'] ?? 20;
        $uri = 'https://ecvdr.pchome.com.tw/vdr/order/v1/index.php/trans/core/vendor/' .$this->merchantId;
        $uri .= '/order?transdate=' .$params['start_date']. '-' .$params['end_date'];
        $uri .= '&offset=' .$params['offset']. '&limit='.$params['limit'];

        $fetch = new FetchHttp;
        $res = $fetch->httpGet($uri);
        if (! empty($res['getinfo']['http_code']) && $res['getinfo']['http_code'] == 200) {
            $output = json_decode($res['output'], true);
            addApiLog('pchome', $uri, $res['output'], $res['getinfo']);
            return [
                'count' => $output['TotalRows'],
                'data' => $output['Rows'],
            ];
        }
        addApiLog('pchome', $uri, $res['output'], $res['getinfo']);
        return [
            'count' => 0,
            'data' => [],
        ];
    }

    /**
     * GET 1.2.10 查訂單明細：用多筆訂單編號
     * https://developers.pchome.tw/#8b83e452-5c11-4f33-8054-ef2bdf3b78cc
     * $pchomeShopping->getProducts(['20250103335894-01', '20250119480967-02']);
     */
    public function getOrderInfo($ids = [])
    {
        $idStr = implode(',', $ids);

        $uri = 'https://ecvdr.pchome.com.tw/vdr/order/v1/index.php/trans/vendor/' .$this->merchantId;
        $uri .= '/order?id='.$idStr;

        $fetch = new FetchHttp;
        $res = $fetch->httpGet($uri);
        if (! empty($res['getinfo']['http_code']) && $res['getinfo']['http_code'] == 200) {
            $output = json_decode($res['output'], true);
            if (! empty($output)) {
                foreach ($output as $i => $v) {
                    unset($output[$i]['ShipInfo']);
                    unset($output[$i]['Buyer']);
                    unset($output[$i]['Receiver']);
                    unset($output[$i]['VirtualInfo']);
                    unset($output[$i]['ReserveInfo']);
                    unset($output[$i]['OriReserveInfo']);
                }
            }
            return $output;
        }
        return [];
    }

    /**
     * https://developers.pchome.tw/#37b569f7-d307-4c0e-8ad7-eb0eeeba1b49
     * 取得商品
     * @param string p_id // 商品編號
     */
    public function getProducts($pId)
    {
        $uri = 'https://ecvdr.pchome.com.tw/vdr/prod/v3.3/index.php/vendor/' .$this->merchantId;
        $uri .= '/prod/'.$pId.'?extra_fields=PmName';

        $fetch = new FetchHttp;
        $res = $fetch->httpGet($uri);
        if (! empty($res['getinfo']['http_code']) && $res['getinfo']['http_code'] == 200) {
            $output = json_decode($res['output'], true);
            if (! empty($output)) {
                foreach ($output as $i => $v) {
                    unset($output[$i]['Pic']);
                    unset($output[$i]['Volume']);
                    unset($output[$i]['PhysicalCategory']);
                }
            }
            return $output;
        }
        return [];
    }

    public function getProductsByGroupId($groupId)
    {
        $uri = 'https://ecvdr.pchome.com.tw/vdr/prod/v3.3/index.php/vendor/' .$this->merchantId;
        $uri .= '/prod?groupid='.$groupId;

        $fetch = new FetchHttp;
        $res = $fetch->httpGet($uri);
        if (! empty($res['getinfo']['http_code']) && $res['getinfo']['http_code'] == 200) {
            $output = json_decode($res['output'], true);
            if (! empty($output)) {
                foreach ($output as $i => $v) {
                    unset($output[$i]['Pic']);
                    unset($output[$i]['Volume']);
                    unset($output[$i]['PhysicalCategory']);
                }
            }
            return $output;
        }
        return [];
    }

    /**
     * https://developers.pchome.tw/#c93d1ca6-3f0a-4c11-9d8c-5305b62a2f38
     * PUT 1.3.8 修改庫存量：用多個商品編號(限筆數與轉單商品使用)
     * 跟 getProducts() 網址一樣，但使用 PUT 方法修改庫存
     */
    public function updQty($pId, $updQty)
    {
        if (app_env == 'production') {
            $uri = 'https://ecvdr.pchome.com.tw/vdr/prod/v3.3/index.php/vendor/' .$this->merchantId;
            $uri .= '/prod/qty?prodid='.$pId;
            //
            $posts = [];
            $posts[] = [
                'Id' => $pId,
                'Qty' => $updQty, // 商品數量(可賣量)
            ];
            //
            $extras = [];
            $extras['_CURLOPT_CUSTOMREQUEST'] = 'PUT';
            //
            $fetch = new FetchHttp;
            $res = $fetch->httpPost($uri, json_encode($posts), $extras);
            if (! empty($res['getinfo']['http_code']) && $res['getinfo']['http_code'] == 204) {
                // 更新庫存 PCHome 設定 204 為成功
                return true;
            }
            return false;
        }
        return true;
    }

    /**
     * 1.2.6 查詢架上商品清單(限分頁筆數)
     */
    public function getEnabledProductList($params = [])
    {
        $uri = 'https://ecvdr.pchome.com.tw/vdr/prod/v3.3/index.php/core/vendor/' .$this->merchantId;
        $uri .= '/prod?isshelf=1&fields=GroupId&limit=50&offset=1';

        $fetch = new FetchHttp;
        $res = $fetch->httpGet($uri);
        if (! empty($res['getinfo']['http_code']) && $res['getinfo']['http_code'] == 200) {
            $output = json_decode($res['output'], true);
            if (! empty($output)) {
                // foreach ($output as $i => $v) {
                    // unset($output[$i]['Pic']);
                    // unset($output[$i]['Volume']);
                    // unset($output[$i]['PhysicalCategory']);
                // }
            }
            return $output;
        }
        return [];
    }
}


class MyPCHome
{
    public $meta;
    
    public function __construct()
    {
        $this->meta = $this->getMeta();
    }

    /**
     * 回傳料號
     */
    public function getPart($order)
    {
        return ! empty($order['Prod']['VendorPID'])
            ? strtoupper($order['Prod']['VendorPID'])
            : '';
    }
    /**
     * 規格, 38
     */
    public function getMatchStr($order)
    {
        return ! empty($order['Prod']['SpecName'])
            ? strtoupper($order['Prod']['SpecName'])
            : '';
    }
    public function getOrderDate($order) 
    {
        $date = $order['TransDate']; // 2025/01/03 13:26:00
        $date = substr($date, 0, 10);
        $date = str_replace('/', '-', $date);
        return $date;
    }
    /**
     * 商品名稱, BOTTEGA VENETA Beak Cabas 三角型翻蓋小牛皮手提水桶包(深咖)
     */
    public function getName($order)
    {
        return ! empty($order['Prod']['Name'])
            ? strtoupper($order['Prod']['Name'])
            : '';
    }
    /**
     * 商品Id, DICKCB-A900GN8U0-000
     */
    public function getId($order)
    {
        return $order['Prod']['Id'] ?? '';
    }
    /**
     * 訂單編號, 20150114090784-01
     */
    public function getOrderNo($order)
    {
        return $order['Id'];
    }

    /**
     * 物流出貨確認狀態 [Error：單號錯誤] [Checking：單號確認] [Shipped：已出貨]
     */
    public function getOrderStatus($order) 
    {
        return $order['ShipCheckStatus'] ?? '';
    }
    /**
     * 轉單日期, "2015/01/14 10:00:00"
     */
    public function getOrderCreatedDate($order)
    {
        $createdDate = substr($order['TransDate'], 0, 10);
        $createdDate = str_replace('/', '-', $createdDate);
        return $createdDate;
    }
    /**
     * 轉成我方狀態
     */
    public function getMyStatus($order)
    {
        switch ($order['ShipCheckStatus']) {
            case 'Error':
                return 'cancel';
            case 'Checking':
                return 'new';
            case 'Shipped':
                return 'shipped';
        }
        return '';
    }
    public function getOrderQty($order)
    {
        return $order['OrderQty'] ?? 0;
    }
    public function getOrderCancelQty($order)
    {
        return $order['CancelQty'] ?? 0;
    }

    /**
     * @param array $order 直接丟入訂單明細
     */
    public function isValid($order)
    {
        $isNotShip = $order['isNotShip']; // 是否不出貨 [0:出貨] [1:不出貨]
        if ($isNotShip == 1) {
            return false;
        }
        return true;
    }


    /**
     * 直接回傳庫存
     */
    public function getQty($pchomeShopping, $pchomeId)
    {
        $remotes = $pchomeShopping->getProducts($pchomeId);
        if (! empty($remotes)) {
            $data = $remotes[0] ?? [];
            return $data['Qty'] ?? 0;
        }
        return 0;
    }



    //
    public function getMeta()
    {
        $metaPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/p_meta.json';
        if (is_file($metaPath)) {
            $meta = file_get_contents($metaPath);
            $meta = json_decode($meta, true);
        } else {
            $meta = [];
            $meta['order_date'] = '2025-09-01';
            $meta['offset'] = 0;
            $meta['limit'] = 20;
            file_put_contents($metaPath, json_encode($meta));
        }
        return $meta;
    }

    public function updMeta()
    {
        $metaPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/p_meta.json';
        //
        $this->meta['order_date'] = str_replace('/', '-', $this->meta['order_date']);
        file_put_contents($metaPath, json_encode($this->meta));
    }
}