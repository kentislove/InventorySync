<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

/**
 * 入庫 2010年建檔 Yahoo商品
 * 取得商品初始庫存
 * 
 * 透過 yahoo/upd_stock.php 下載全部產品CSV
 * 匯入 my_listings.i_stock 或是 y_products.init_stock
 */
$startTime = time();
echo "開始: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB<br>";
readYahooCSV();
$time = time() - $startTime;
echo "結束: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB, 時間: " .$time. "<br>";
function readYahooCSV()
{
    $myYahoo = new MyYahoo;

    $yahooShopping = new YahooShopping;
    $yahooShopping->login();

    // 一次讀取 200行，更新 csv
    $readCnt = 0;
    $readMax = 43;
    $header = [];
    $data = [];

    // 一個一個處理，處理完會檔案大小 0
    $dirPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/tmp/yahoo_notify-*.csv';
    $files = glob($dirPath);
    foreach ($files as $i => $csvPath) {
        if ($i > 0) {
            echo "一次只開啟一個 csv: " .$csvPath. "<br>\r\n";
            continue;
        }
        $filesize = filesize($csvPath);
        if (! $filesize) {
            echo "檔案大小為0<br>\r\n";
            continue;
        }
        
        // 複製貼上
        if (($handle = fopen($csvPath, 'r')) !== false) {
            // 第一行欄位名稱，由料號格式過濾掉
            while (($v = fgetcsv($handle)) !== false) {
                $readCnt++;
                if ($readCnt == 1) { // 標題列
                    $header = $v;
                    continue;
                } elseif ($readCnt > $readMax) {
                    $data[] = $v;
                    continue;
                }
                $sku = $v[7];
                $yId = $sku;
                // 檢查料號格式 2240026-B3, 2240036-02
                $part = strtoupper($v[9]);
                preg_match('/^([0-9]+\-[0-9a-zA-Z]{2})$/', $part, $matches);
                if (empty($matches)) {
                    // 不是正規料號, 有些會帶品牌, 有些空白, 有些帶 -
                    echo "料號不符合格式:" .$part. ", y_id:" .$yId. " <br>\r\n";
                    echo "csv:" .$csvPath. " <br>\r\n";
                    continue;
                }
                $name = strtoupper($v[3]);
                $attr = strtoupper($v[5]);
                $price = $v[6];
                $brand = strtoupper($v[8]);
                $stock = $v[14]; // 可售數量(A-B)
                //
                $strSQL = "SELECT * FROM y_products WHERE y_id = :y_id ";
                $sth = $GLOBALS['dbh']->prepare($strSQL);
                $sth->execute(['y_id' => $yId]);
                $exist = $sth->fetch();
                if (! $exist) {
                    $dt = date('Y-m-d H:i:s');
                    //
                    $remoteY = $yahooShopping->getProducts(['y_id' => $yId]);
                    $remoteY = $remoteY['products'][0];
                    // unset($remoteY['images']);
                    // unset($remoteY['structuredData']);
                    // unset($remoteY['shipType']);
                    // echo "<pre>" .print_r($remoteY, true). "</pre>";
                    $matchStr = $myYahoo->getMatchStr($remoteY);
                    $attr1 = $myYahoo->getAttr1($remoteY);
                    $attr2 = $myYahoo->getAttr2($remoteY);

                    $inputs = [
                        'y_id' => $yId,
                        'part' => $part,
                        'match_str' => $matchStr ?? '',
                        'attr1' => $attr1 ?? '',
                        'attr2' => $attr2 ?? '',
                        'name' => $name,
                        'csv_match_str' => $attr,
                        'updated_at' => $dt,
                    ];
                    $tmp = pdo_insert_sql('y_products', $inputs);
                    $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
                    $sth->execute($tmp['params']);
                    $res = $sth->rowCount();
                    if ($res) {
                        echo "新增 " .$readCnt. " id: ".$yId.", " .$part. "+" .$attr. "<br>\r\n";
                    }
                } else {
                    echo "已經存在:" .$part. ", y_id:" .$yId. " <br>\r\n";
                    echo "csv:" .$csvPath. " <br>\r\n";
                }
            }
            fclose($handle);
            
            // 寫回檔案
            if (! empty($data)) {
                if (($handle = fopen($csvPath, 'w')) !== false) {
                    // 寫入標題列
                    fputcsv($handle, $header);
                    // 寫入資料
                    foreach ($data as $row) {
                        fputcsv($handle, $row);
                    }
                    fclose($handle);
                }
            } else {
                echo "資料解析完畢..".$csvPath;
                unlink($csvPath);
            }
        } else {
            echo "無法開啟檔案";
        }
    }
}