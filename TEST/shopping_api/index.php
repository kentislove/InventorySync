<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/shopping_api/functions.php');

use App\Libraries\FetchHttp;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>平台進貨補量</title>
    <link rel="shortcut icon" href="images/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="/assets/plugins/pace/minimal.css">
    <link rel="stylesheet" href="style.css?v=9743548">
    <?php include($_SERVER['DOCUMENT_ROOT'].'/shopping_api/_ga.php'); ?>
</head>
<body>
    <?php
    $keyword = ! empty($_GET['keyword']) ? trim($_GET['keyword']) : '';

    $yProducts = [];
    $mRemotes = [];
    if ($keyword != '') {
        $yahooShopping = new YahooShopping;
        $yahooShopping->login();
        $myYahoo = new MyYahoo;

        $momoShopping = new MomoShopping;
        $myMomo = new MyMomo;

        $yProducts = $myYahoo->getByPart($yahooShopping, $keyword);
        $mRemotes = $momoShopping->getProducts($keyword);
        foreach ($mRemotes as $i => $v) {
            if ($v['sale_gb_name'] != '進行') {
                continue;
            }
            $arr = $momoShopping->getQty($v['goods_code']);
            foreach ($arr as $ii => $vv) {
                if ($v['goodsdt_code'] == $vv['goodsdt_code']
                    && $v['goodsdt_info'] == $vv['goodsdt_info']) {
                        // $mRemotes[$i]['qty'] = $vv['syslast'];
                        $mRemotes[$i]['qty'] = $vv['order_counsel_qty'];
                        
                }
            }
        }
    }
    

    //
    $momo = $_POST['momo'] ?? '';
    if ($momo != '') {
        momo_UpdEntpPassword($momo);
    }
    ?>
    <br>
    <form id="momoform" action="" method="post">
        MOMO密碼 <input type="text" name="momo" id="momo" value="" autocomplete="0">
        <button>變更MOMO密碼</button>
        <button type="button" id="btn-momo">測試</button>
    </form>
    <hr>
    <section>
        <div>
            <h3>料號查詢</h3>
            <form action="" method="get">
                <input type="text" name="keyword" id="keyword" value="<?=$keyword;?>" maxlength="20" placeholder="料號" autocomplete="0">
                <button>查詢</button>
                <a href="order.php" target="_blank" class="btn-1">查看訂單</a>
            </form>
        </div>
        <br>
        <div>
            <div>
                <table class="y_tb">
                    <thead>
                        <tr>
                            <th class="f_part">料號</th>
                            <th class="f_code">商品編號</th>
                            <th>Yahoo</th>
                            <th>屬性</th>
                            <th class="f_qty">在線庫存</th>
                            <th class="f_op">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yProducts as $i => $v) { ?>
                        <tr>
                            <td class="f_part"><?=$v['partNo'];?></td>
                            <td f="y_id" class="f_code"><?=$v['id'];?></td>
                            <td><?=$v['name'];?></td>
                            <td class="f_attr2"><?=($v['_attr2']);?></td>
                            <td f="qty" class="f_qty"><?=$v['_stock'];?></td>
                            <td class="f_op"><input type="text" name="counts[]" value="" maxlength="2">
                                <button type="button" class="btn-upd-yahoo">變更</button>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <br>
            <div>
                <table class="m_tb">
                    <thead>
                        <tr>
                            <th class="f_part">料號</th>
                            <th class="f_code">商品編號</th>
                            <th>MOMO</th>
                            <th>屬性</th>
                            <th class="f_attr2">屬性</th>
                            <th>狀態</th>
                            <th class="f_qty">在線庫存</th>
                            <th class="f_op">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mRemotes as $i => $v) { ?>
                        <tr>
                            <td f="part" class="f_part"><?=$v['entp_goods_no'];?></td>
                            <td f="goods_code" class="f_code"><?=$v['goods_code'];?></td>
                            <td f="goods_name"><?=$v['goods_name'];?></td>
                            <td f="goodsdt_code"><?=$v['goodsdt_code'];?></td>
                            <td f="goodsdt_info" class="f_attr2"><?=$v['goodsdt_info'];?></td>
                            <td f="status" nowrap><?=$v['sale_gb_name'];?></td>
                            <td f="qty" class="f_qty"><?=$v['qty'];?></td>
                            <td class="f_op"><input type="text" name="counts[]" value="" maxlength="2">
                                <button type="button" class="btn-upd-momo">變更</button>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <script src="/assets/js/jquery.min.js"></script>
    <script src="/assets/plugins/pace/pace.js"></script>
    <script src="index.js?v=974121"></script>
    <script>
    $('body').on('click', 'button.btn-upd-yahoo', function () {
        var that = $(this),
        tr = that.closest('tr'),
        input_qty = tr.find('input[name="counts[]"]').val(),
        y_id = tr.find('[f="y_id"]').html();
        //
        const fd = new FormData();
        fd.append('action', 'upd_yahoo');
        fd.append('y_id', y_id);
        fd.append('upd_qty', input_qty);
        $.ajax({
            url: 'index_ajax.php',
            type: 'post',
            dataType: 'json',
            data: fd,
            processData: false,
            contentType: false,
            success: function (r) {
                if (r.message) {
                    alert(r.message);
                }
                if (r.onlineQty) {
                    tr.find('input[name="counts[]"]').val('');
                    tr.find('[f="qty"]').html(r.onlineQty);
                }
            },
            error: function (xhr, status) {
                console.log(status);
            },
        });
    });
    $('body').on('click', 'button.btn-upd-momo', function () {
        var that = $(this),
        tr = that.closest('tr'),
        input_qty = tr.find('input[name="counts[]"]').val(),
        goods_code = tr.find('[f="goods_code"]').html(),
        part = tr.find('[f="part"]').html(),
        goods_name = tr.find('[f="goods_name"]').html(),
        goodsdt_code = tr.find('[f="goodsdt_code"]').html(),
        goodsdt_info = tr.find('[f="goodsdt_info"]').html(),
        status = tr.find('[f="status"]').html(),
        qty = tr.find('[f="qty"]').html();
        if (input_qty == '') {
            alert(`請輸入數量`);
            return ;
        }
        //
        const fd = new FormData();
        fd.append('action', 'upd_momo');
        fd.append('upd_qty', input_qty);
        fd.append('goods_code', goods_code);
        fd.append('part', part);
        fd.append('goods_name', goods_name);
        fd.append('goodsdt_code', goodsdt_code);
        fd.append('goodsdt_info', goodsdt_info);
        fd.append('status', status);
        fd.append('qty', qty);
        $.ajax({
            url: 'index_ajax.php',
            type: 'post',
            dataType: 'json',
            data: fd,
            processData: false,
            contentType: false,
            success: function (r) {
                if (r.message) {
                    alert(r.message);
                }
                if (r.onlineQty) {
                    tr.find('input[name="counts[]"]').val('');
                    tr.find('[f="qty"]').html(r.onlineQty);
                }
            },
            error: function (xhr, status) {
                console.log(status);
            },
        });
    });
    </script>
</body>
</html>