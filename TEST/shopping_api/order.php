<?php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

// https://www.jeasyui.com/demo/main/index.php?plugin=DataGrid&theme=material-teal&dir=ltr&pitem=&sort=asc#
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>平台訂單狀況</title>
    <link rel="shortcut icon" href="images/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="/assets/plugins/pace/minimal.css">
    <link rel="stylesheet" href="style.css?v=1764149595">
    <?php include($_SERVER['DOCUMENT_ROOT'].'/shopping_api/_ga.php'); ?>
    <style>
    .m_info {
        color: #ef00c5;
    }
    .y_info {
        color: #7d2eff;
    }
    .p_info {
        color: #ea1716;
    }
    .msg {
        color: red;
    }
    </style>
</head>
<body>
    <?php
    // $myYahoo = new MyYahoo;
    // $tmpY = $myYahoo->getYIdByMomoGoodsCode('2510281-85', '14584015', '灰藍尾納帕皮杏色 /37');
    // echo "<pre>" .print_r($tmpY, true). "</pre>";
    // exit;


    $keyword = $_GET['keyword'] ?? '';
    $get['order_no'] = $_GET['order_no'] ?? '';
    $get['third_status'] = ! empty($_GET['third_status']) ? $_GET['third_status'] : '待出貨';
    $get['third_channel'] = ! empty($_GET['third_channel']) ? $_GET['third_channel'] : '';
    $page = $_GET['page'] ?? 1;
    $limit = $_GET['limit'] ?? 200;
    if ($page < 1 || ! is_numeric($page)) {
        $page = 1;
    }
    $offset = ($page - 1) * $limit;
    //
    $quyStr = '';
    if ($keyword != '') {
        $quyStr .= "AND (O.part LIKE '%" .$keyword. "%' OR third_no LIKE '%" .$keyword. "%' )";
    }
    if ($get['order_no'] != '') {
        $quyStr .= "AND (third_no LIKE '%" .$get['order_no']. "%' )";
    }
    if ($get['third_status'] == '待出貨') {
        $quyStr .= 'AND my_status = "new" ';
    } elseif ($get['third_status'] == '已出貨') {
        $quyStr .= 'AND my_status = "shipped" ';
    } elseif ($get['third_status'] == '取消單') {
        $quyStr .= 'AND my_status = "cancel" ';
    }
    if ($get['third_channel']) {
        $quyStr .= 'AND third_channel = "' .$get['third_channel']. '" ';
    }
    // 總筆數
    $cntSQL = 'SELECT COUNT(id) AS cnt FROM orders as O WHERE 1 '.$quyStr;
    $sth = $GLOBALS['dbh']->prepare($cntSQL);
    $sth->execute();
    $res = $sth->fetch();
    $cnt = $res['cnt'] ?? 0;
    // 總頁數
    $pages = ceil($cnt / $limit);
    $page = $page > $pages ? $pages : $page;
    //
    $offsetSQL = 'LIMIT '.$offset.', '.$limit.' ';
    //
    $strSQL = "SELECT O.*, AP.local_stock, AP.y_stock, AP.m_stock, AP.p_stock FROM orders as O LEFT JOIN all_products as AP ON O.y_id = AP.y_id WHERE 1 ";
    $strSQL .= $quyStr;
    $strSQL .= "ORDER BY my_status ASC, O.third_create_date DESC ";
    $strSQL .= $offsetSQL;
    ?>
    總筆數：<?=$cnt;?> / 頁數：<?=$pages;?>
    <form action="" method="get">
        <select name="third_status" id="third_status">
            <option value="待出貨" <?=($get['third_status'] == '待出貨' ? 'selected' : '');?>>待出貨</option>
            <option value="已出貨" <?=($get['third_status'] == '已出貨' ? 'selected' : '');?>>已出貨</option>
            <option value="取消單" <?=($get['third_status'] == '取消單' ? 'selected' : '');?>>取消單</option>
        </select>
        <select name="third_channel" id="third_channel">
            <option value="">平台</option>
            <option value="yahoo" <?=($get['third_channel'] == 'yahoo' ? 'selected' : '');?>>Yahoo</option>
            <option value="momo" <?=($get['third_channel'] == 'momo' ? 'selected' : '');?>>momo</option>
            <option value="pchome" <?=($get['third_channel'] == 'pchome' ? 'selected' : '');?>>PCHome</option>
        </select>
        <input type="text" name="order_no" id="order_no" value="<?=$get['order_no'];?>" maxlength="20" placeholder="單號">
        <input type="text" name="keyword" id="keyword" value="<?=$keyword;?>" maxlength="20" placeholder="料號">
        <button>查詢</button>
        <input type="text" name="page" id="page" value="<?=$page;?>" style="width: 20px;"> 頁
        <input type="text" name="limit" id="limit" value="<?=$limit;?>" style="width: 30px;"> 筆數
    </form>
    <br>
    <div class="log">
        <?php
        $logs = getOpLog(200);
        krsort($logs);
        foreach ($logs as $log) {
            echo $log."<br>";
        }
        ?>
    </div>
    <br>
    <?php
    echo '<table style="width: 98%">';
    echo '<tr>';
    echo '<td width="30">#</td>';
    echo '<td width="70">平台</td>';
    echo '<td width="260">訂單</td>';
    echo '<td>料號</td>';
    echo '<td width="100">訂單日期</td>';
    echo '<td width="70" style="color: blue;">即時</td>';
    echo '<td width="70"></td>';
    echo '<td width="60"></td>';
    echo '</tr>';
    $sth = $GLOBALS['dbh']->prepare($strSQL);
    $sth->execute();
    $dataCnt = 0;
    while ($v = $sth->fetch()) {
        $dataCnt++;
        // $isSync = $v['is_sync'] == 'y' ? 'disabled' : '';
        $isSync = 'disabled';

        switch ($v['third_channel']) {
            case 'yahoo':
                $infoStr = 'Y: '.$v['y_id'].' '.$v['part'].'+'.$v['attr2'];
                if ($v['goods_code']) {
                    $infoStr .= '<div class="m_info">M: ' .$v['goods_code']. '</div>';
                }
                if ($v['p_id']) {
                    $infoStr .= '<div class="p_info">P: ' .$v['p_id']. '</div>';
                }
                break;
            case 'momo':
                $infoStr = $v['part'].'+'.$v['attr2'];
                $infoStr .= '<div class="m_info">M: ' .$v['goods_code']. '</div>';
                if ($v['y_id']) {
                    $infoStr .= '<div class="y_info">Y: ' .$v['y_id']. '</div>';
                }
                break;
            case 'pchome':
                $infoStr = $v['part'].'+'.$v['attr2'];
                $infoStr .= '<div class="p_info">P: ' .$v['p_id'].'</div>';
                if ($v['y_id']) {
                    $infoStr .= '<div class="y_info">Y: ' .$v['y_id'].'</div>';
                }
                break;
        }

        if ($v['message']) {
            $infoStr .= '<div class="msg">' .$v['message']. '</div>';
        }

        $btnSkip = '';
        if ($v['is_sync'] == 'y') {
            $btnSkip = '<span class="text-warning">已同步</span>';
        } elseif ($v['is_sync'] == 'n') {
            $btnSkip = '<button type="button" class="btn-skip">略過同步</button>';
        } elseif ($v['is_sync'] == 'x') {
            $btnSkip = '<span class="text-warning">已手動</span>';
        } elseif ($v['is_sync'] == 'xx') {
            $btnSkip = '<span class="text-warning">配對不到</span>';
        }
        echo '<tr data-id="' .$v['id']. '">';
        echo "<td>" .$dataCnt. "</td>";
        echo '<td>' .$v['third_channel'].'<br>' .$gMyOrderStatus[$v['my_status']]. '</td>';
        echo '<td>' .$btnSkip.'<br>'.$v['third_no'].'</td>';
        echo "<td>" .$infoStr. "</td>";
        echo "<td>" .$v['third_create_date']. "</td>";
        echo "<td>";
        echo "<div>Y:<span f='y_stock'></span></div>";
        echo "<div>M:<span f='m_stock'></span></div>";
        echo "<div>P:<span f='p_stock'></span></div>";
        echo "</td>";
        echo '<td>';
        echo '<div><button type="button" class="api">即時庫存</button></div>';
        echo '<div><button type="button" act="match" ' .($v['third_channel'] != 'pchome' ? '' : 'disabled'). '>配對</button></div>';
        echo '</td>';
        echo '<td>';
        echo '<div><input type="number" name="upd_qty[]" value="" maxlength="1" min="0"></div>';
        echo '<button type="button" class="upd_qty">變更庫存</button>';
        echo '</td>';
        echo "</tr>";
    }
    echo '</table>';
    ?>
    <ul>
        <li>每隔15分鐘會刷平台訂單資料進來</li>
        <li>訂單每隔15分鐘會刷進來</li>
    </ul>
    <script src="/assets/js/jquery.min.js"></script>
    <script src="/assets/plugins/pace/pace.js"></script>
    <script src="order.js?v=1764149595"></script>
</body>
</html>