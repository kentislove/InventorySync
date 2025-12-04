<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

/**
 * 入庫 PCHome 全部商品
 */
$startTime = time();
echo "開始: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB<br>";
readPCHomeCSV();
$time = time() - $startTime;
echo "結束: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB, 時間: " .$time. "<br>";
function readPCHomeCSV()
{
    $myPCHome = new MyPCHome;

    $pchomeShopping = new PCHomeShopping;

    // 一個一個處理，處理完會檔案被刪除
    $csvPath = $_SERVER['DOCUMENT_ROOT'].'/shopping_api/tmp/PCHome-ALLProducts-1022.csv';
    if (! is_file($csvPath)) {
        echo "檔案不存在<br>\r\n";
        echo "Path: ".$csvPath."<br>\r\n";
        return ;
    }

    // 一次讀取 200行，更新 csv
    $readCnt = 0;
    $readMax = 100;
    $header = [];
    $data = [];
    if (($handle = fopen($csvPath, 'r')) !== false) {
        // 第一行欄位名稱，由料號格式過濾掉
        while (($v = fgetcsv($handle)) !== false) {
            $readCnt++;
            
            if ($readCnt <= 4) {
                // 前幾行是介紹，跳過
                continue;
            } elseif ($readCnt == 5) {
                // 標題列
                $header = $v;
                continue;
            } elseif ($readCnt > $readMax) {
                $data[] = $v;
                continue;
            }

            $pId = $v[4];
            $name = $v[5];
            $attr1 = $v[6];
            $status = $v[8];
            $stock = $v[12];
            $part = $v[17];


            $strSQL = "SELECT * FROM p_products WHERE pchome_id = :pchome_id ";
            $sth = $GLOBALS['dbh']->prepare($strSQL);
            $sth->execute(['pchome_id' => $pId]);
            $exist = $sth->fetch();
            if (! $exist) {
                $dt = date('Y-m-d H:i:s');

                $inputs = [];
                $inputs['pchome_id'] = $pId;
                $inputs['part'] = $part;
                $inputs['match_str'] = $attr1;
                $inputs['name'] = $name;
                $inputs['status'] = $status;
                $inputs['updated_at'] = $dt;

                $tmp = pdo_insert_sql('p_products', $inputs);
                $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
                $sth->execute($tmp['params']);
                $res = $sth->rowCount();
                if ($res) {
                    echo "新增 " .$readCnt. " pchome_id: ".$pId.", " .$part. "+" .$attr1. "<br>\r\n";
                }
            } else {
                echo "已經存在:" .$part. " pchome_id: ".$pId.", " .$part. "+" .$attr1. "<br>\r\n";
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
    }
}