<?php

use App\Libraries\FetchHttp;

class MomoShopping
{
    public $loginInfo;

    /**
     * order_date
     */
    public $meta;

    public function __construct()
    {
        $entpPwd = momo_GetEntpPassword();

        $this->loginInfo = [];
        $this->loginInfo['entpID'] = '53617790'; // 統編
        $this->loginInfo['entpCode'] = '027410'; // 廠商編號
        $this->loginInfo['entpPwd'] = $entpPwd; // master 密碼 BB22356664
        $this->loginInfo['otpBackNo'] = '416';

        $this->meta = $this->getMeta();
    }

    /**
     * 取得庫存
     * 推薦使用 MyMomo@getAttrQty
     */
    public function getQty($goodsCode)
    {
        $fetch = new FetchHttp;
        $uri = 'https://scmapi.momoshop.com.tw';
        $apiUri = $uri.'/api/v1/goodsStockQty/query.scm'; // 商品加減量-指定品號查詢：庫存查詢的意思

        $posts = [];
        $posts['doAction'] = 'query';
        $posts['loginInfo'] = $this->loginInfo;
        //
        $arr = [];
        $arr['goodsCodeList'] = [$goodsCode]; // JSON 最多可查詢2000筆
        $posts = array_merge($posts, $arr);
        //
        $headers = [];
        $headers['Content-Type'] = 'application/json';

        $extras = [];
        $extras['headers'] = $headers;

        $res = $fetch->httpPost($apiUri, json_encode($posts), $extras);
        if ($res['getinfo']['http_code'] == 400) {
            // addOpLog(['message' => 'MOMO: '.$res['output']]);
            return []; 
        }
        $output = json_decode($res['output'], true);
        $data = $output['dataList'] ?? [];

        return $data;
        // (
        //     [entp_goods_no] => 2530132-01
        //     [goods_code] => 14431070
        //     [goods_name] => 【TOD’S】Timeless 35mm 黑色x酒紅雙釦頭壓紋牛皮腰帶禮盒(腰帶 皮帶)
        //     [goodsdt_code] => 001
        //     [goodsdt_info] => -90
        //     [order_counsel_qty] => 3 // 可銷售數
        //     [syslast] => 0
        // )
    }

    /**
     * 對帳內容查詢 API
     */
    public function getShippingOrders($params = [])
    {
        $uri = 'https://scmapi.momoshop.com.tw';
        $apiUri = $uri.'/api/v2/accounting/order/C1105.scm'; // 出庫訂單明細查詢
        //
        $posts = [];
        $posts['loginInfo'] = $this->loginInfo;

        $arr = [];
        $arr['fromDate'] = $params['start_date'] ?? date('Y/m/01'); // *
        $arr['toDate'] = $params['end_date'] ?? date('Y/m/d'); // *
        $arr['goodsCode'] = $params['goods_code'] ?? ''; // 商品編號
        $arr['delyGbType'] = "1"; // 配送類型 [1:全部]
        $arr['sendRecoverType'] = '1'; // 出庫類型 [0:換貨出庫] [1:一般訂單]
        $posts = array_merge($posts, $arr);
        //
        $headers = [];
        $headers['Content-Type'] = 'application/json';

        $extras = [];
        $extras['headers'] = $headers;

        $fetch = new FetchHttp;
        $res = $fetch->httpPost($apiUri, json_encode($posts), $extras);
        if ($res['getinfo']['http_code'] == 400) {
            addOpLog(['message' => 'MOMO: '.$res['output']]); // {"ERROR":"密碼輸入錯誤，請重新確認輸入密碼為master密碼"}
            return []; 
        }
        $output = json_decode($res['output'], true);
        return $output;
    }

    /**
     * 對帳內容查詢 API 
     * 回收訂單明細查詢
     */
    public function getReturnOrders($params = [])
    {
        $uri = 'https://scmapi.momoshop.com.tw';
        $apiUri = $uri.'/api/v2/accounting/return/C1105.scm'; // 回收訂單明細查詢
        //
        $posts = [];
        $posts['loginInfo'] = $this->loginInfo;

        $arr = [];
        $arr['fromDate'] = $params['start_date'] ?? date('Y/m/d', strtotime('-7 day')); // *
        $arr['toDate'] = $params['end_date'] ?? date('Y/m/d'); // *
        $arr['goodsCode'] = $params['goods_code'] ?? ''; // 商品編號
        $arr['delyGbType'] = "1"; // *配送類型 [1:全部]
        $arr['delyGb'] = ''; // 訂單類型 [1:廠商配送] [21:超商取貨] [6:第三方貨運]
        $arr['orderNo'] = '';
        $arr['orderG'] = ''; // 商品序號
        $arr['orderD'] = ''; // 商品細節序號
        $arr['orderW'] = ''; // 訂單處理序號
        $arr['giftYn'] = '';
        $arr['sendRecoverType'] = '1'; // 出庫類型 [0:換貨出庫] [1:一般訂單]
        $posts = array_merge($posts, $arr);
        //
        $headers = [];
        $headers['Content-Type'] = 'application/json';

        $extras = [];
        $extras['headers'] = $headers;

        $fetch = new FetchHttp;
        $res = $fetch->httpPost($apiUri, json_encode($posts), $extras);
        if ($res['getinfo']['http_code'] == 400) {
            addOpLog(['message' => 'MOMO: '.$res['output']]); // {"ERROR":"密碼輸入錯誤，請重新確認輸入密碼為master密碼"}
            return []; 
        }
        $output = json_decode($res['output'], true);
        return $output;
    }
    /**
     * 對帳內容查詢 API 
     * 保留款訂單明細查詢
     */
    public function getRetentionOrders($params = [])
    {
        $uri = 'https://scmapi.momoshop.com.tw';
        $apiUri = $uri.'/api/v2/accounting/retention/C1105.scm'; // 回收訂單明細查詢
        //
        $posts = [];
        $posts['loginInfo'] = $this->loginInfo;

        $arr = [];
        $arr['purchaseMon'] = $params['start_ym'] ?? date('Y/m'); // *請款年月 YYYY/MM
        $arr['goodsCode'] = $params['goods_code'] ?? ''; // 商品編號
        $arr['delyGbType'] = "1"; // *配送類型 [1:全部] [2：訂單] [3：寄倉訂單]
        $arr['delyGb'] = ''; // 訂單類型 [1:廠商配送] [21:超商取貨] [6:第三方貨運]
        $arr['orderNo'] = '';
        $arr['orderG'] = ''; // 商品序號
        $arr['orderD'] = ''; // 商品細節序號
        $arr['orderW'] = ''; // 訂單處理序號
        $posts = array_merge($posts, $arr);
        //
        $headers = [];
        $headers['Content-Type'] = 'application/json';

        $extras = [];
        $extras['headers'] = $headers;

        $fetch = new FetchHttp;
        $res = $fetch->httpPost($apiUri, json_encode($posts), $extras);
        if ($res['getinfo']['http_code'] == 400) {
            addOpLog(['message' => 'MOMO: '.$res['output']]); // {"ERROR":"密碼輸入錯誤，請重新確認輸入密碼為master密碼"}
            return []; 
        }
        $output = json_decode($res['output'], true);
        return $output;
    }

    /**
     * 查詢商品
     */
    public function getProducts($part)
    {
        $uri = 'https://scmapi.momoshop.com.tw';
        $apiUri = $uri.'/api/v1/goodsSaleStatus/query.scm'; // (八)、商品上下架-分頁查詢
        //
        $sendInfo = [];
        $sendInfo['goodsCode'] = ''; // 商品編號, 可前後模糊搜尋
        $sendInfo['goodsName'] = ''; // 商品名稱, 可前後模糊搜尋
        $sendInfo['entpGoodsNo'] = $part ?? ''; // 原廠編號, 可前後模糊搜尋
        $sendInfo['saleGB'] = '00'; // 銷售狀況, [空值:全部] [00:進行] [11:暫時中斷]
        $sendInfo['delyType'] = ''; // 配送類別選擇 [空值:全部]
        $sendInfo['outForNoGoods'] = ''; // 無量自動下架
        $sendInfo['page'] = 1; // 每頁 1000筆
        //
        $posts = [];
        $posts['loginInfo'] = $this->loginInfo;
        $posts['sendInfo'] = $sendInfo;
        //
        $headers = [];
        $headers['Content-Type'] = 'application/json';
        //
        $extras = [];
        $extras['headers'] = $headers;
        $fetch = new FetchHttp;
        $res = $fetch->httpPost($apiUri, json_encode($posts), $extras);
        if ($res['getinfo']['http_code'] == 400) {
            addOpLog(['message' => 'MOMO: #'.$part.':'.$res['output']]);
            return []; 
        }
        $output = json_decode($res['output'], true);
        return $output['dataList'] ?? [];
        // [entp_goods_no] => 2510278-37
        // [entp_remark] => 
        // [for_out_excel_note] => 0
        // [goods_code] => 14342687
        // [goods_name] => 【HOGAN】專櫃經典 長腿神器餅乾鞋-男女款可選(餅乾鞋)
        // [goodsdt_code] => 018
        // [goodsdt_info] => 男側黑H 9
        // [international_no] => 
        // [sale_gb_name] => 進行
        // [sale_no_note] => 
        // [wh_name] => 供應商
    }

    public function getMeta()
    {
        $metaPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/m_meta.json';
        if (is_file($metaPath)) {
            $meta = file_get_contents($metaPath);
            $meta = json_decode($meta, true);
        } else {
            $meta = [];
            $meta['order_date'] = '2025-09-01';
            file_put_contents($metaPath, json_encode($meta));
        }
        return $meta;
    }

    public function updMeta()
    {
        $metaPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/m_meta.json';
        file_put_contents($metaPath, json_encode($this->meta));
    }

}

class MyMomo
{
    public function getGoodsInfo($str)
    {
        if ($str == '無') {
            $str = '';
        }
        return $str;
    }
    public function getAttr1($goodsdt_info = '')
    {
        $tmp = $goodsdt_info == '無' ? '' : $goodsdt_info;
        $arr = explode('/', $tmp);
        $attr1 = isset($arr[0]) ? trim($arr[0]) : '';
        return $attr1;
    }
    public function getAttr2($goodsdt_info = '')
    {
        $tmp = $goodsdt_info == '無' ? '' : $goodsdt_info;
        $arr = explode('/', $tmp);
        $attr2 = isset($arr[1]) ? trim($arr[1]) : '';
        return $attr2;
    }
    public function getMatchStr($goodsdt_info = '')
    {
        $attr1 = $this->getAttr1($goodsdt_info);
        $attr2 = $this->getAttr2($goodsdt_info);

        $arr = [];
        if ($attr1 != '') {
            $arr[] = $attr1;
        }
        if ($attr2 != '') {
            $arr[] = $attr2;
        }
        $str = implode(' ', $arr);
        return $str;
    }
    /**
     * MomoShopping@getQty 會取得多個規格庫存
     * 取得單一尺寸的商品庫存
     */
    public function getAttrQty($goodsCode, $goodsdtInfo = '')
    {
        $momoShopping = new MomoShopping;
        $data = $momoShopping->getQty($goodsCode);
        foreach ($data as $i => $v) {
            if ($v['goodsdt_info'] == $goodsdtInfo) {
                return $v['order_counsel_qty'];
            }
        }
        return 'XXX';
    }
}