$(document)
.ajaxStart(function() { Pace.restart(); })
.ajaxStop(function() { Pace.stop(); });

function rePage() {
    setTimeout(function () {
        location.reload();
        rePage();
    }, 1000 * 60 * 13);
}
rePage();

$('button[act="match"]').click(function () {
    let that = $(this),
    tr = that.closest('tr'),
    id = tr.data('id');
    var fd = new FormData();
    fd.append('act', 'match');
    fd.append('id', id);
    $.ajax({
        url: 'order_ajax.php',
        type: 'post',
        dataType: 'json',
        data: fd,
        processData: false,
        contentType: false,
        success: function (r) {
            if (r.message) {
                alert(r.message);
            }
            if (r.success) {
                location.reload();
            }
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
});
$('button[act="sync"]').click(function () {
    let that = $(this),
    tr = that.closest('tr'),
    id = tr.data('id');
    var fd = new FormData();
    fd.append('act', 'sync');
    fd.append('id', id);
    $.ajax({
        url: 'order_ajax.php',
        type: 'post',
        dataType: 'json',
        data: fd,
        processData: false,
        contentType: false,
        success: function (r) {
            if (r.message) {
                alert(r.message);
            }
            if (r.success) {
                location.reload();
            }
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
});
$('.api').click(function () {
    var tr = $(this).closest('tr');
    id = tr.data('id');

    var fd = new FormData();
    fd.append('act', 'realtime');
    fd.append('id', id);
    $.ajax({
        url: 'order_ajax.php',
        type: 'post',
        dataType: 'json',
        data: fd,
        processData: false,
        contentType: false,
        success: function (r) {
            tr.find('span[f="y_stock"]').html(r.y_stock);
            tr.find('span[f="m_stock"]').html(r.m_stock);
            tr.find('span[f="p_stock"]').html(r.p_stock);
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
})


$('.upd_qty').click(function () {
    var tr = $(this).closest('tr');
    id = tr.data('id'),
    updQty = tr.find('input[name="upd_qty[]"]').val();

    var fd = new FormData();
    fd.append('act', 'upd_qty');
    fd.append('id', id);
    fd.append('updQty', updQty);
    $.ajax({
        url: 'order_ajax.php',
        type: 'post',
        dataType: 'json',
        data: fd,
        processData: false,
        contentType: false,
        success: function (r) {
            if (r.message) {
                alert(r.message);
            }
            if (r.success) {
                location.reload();
            }
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
})

$('.btn-skip').click(function () {
    let that = $(this),
    tr = that.closest('tr'),
    id = tr.data('id');
    let fd = new FormData();
    fd.append('act', 'skip_sync');
    fd.append('id', id);
    $.ajax({
        url: 'order_ajax.php',
        type: 'post',
        dataType: 'json',
        data: fd,
        processData: false,
        contentType: false,
        success: function (r) {
            if (r.message) {
                alert(r.message);
            }
            if (r.success) {
                location.reload();
            }
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
});




// function rePage()
// {
//     setTimeout(() => {
        
//     }, 1000 * 60 * 6);
// }

// let timer;
// document.addEventListener('mousemove', () => {
//   console.log('mouse moving');

//   clearTimeout(timer);

//   timer = setTimeout(() => {
//     console.log('mouse stopped');
//   }, 500); // 0.5 秒沒移動視為停止
// });