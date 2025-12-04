$(document)
.ajaxStart(function() { Pace.start(); })
.ajaxStop(function() { Pace.stop(); });


function rePage() {
    setTimeout(function () {
        location.reload();
        rePage();
    }, 1000 * 60 * 8);
}
rePage();

$('.api').click(function () {
    var tr = $(this).closest('tr');
    id = tr.data('id');

    var fd = new FormData();
    fd.append('action', 'api');
    fd.append('id', id);
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
            if (r.success) {
                location.reload();
            }
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
})
$('.sync').click(function () {
    var tr = $(this).closest('tr');
    id = tr.data('id'),
    updQty = tr.find('input[name="upd_qty[]"]').val();

    var fd = new FormData();
    fd.append('action', 'sync');
    fd.append('id', id);
    fd.append('updQty', updQty);
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
            if (r.success) {
                location.reload();
            }
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
})
$('.match').click(function () {
    var tr = $(this).closest('tr');
    id = tr.data('id');

    var fd = new FormData();
    fd.append('action', 'match');
    fd.append('id', id);
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
            if (r.success) {
                location.reload();
            }
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
})
$('#btn-momo').click(function () {
    var fd = new FormData();
    fd.append('action', 'momo_testing');
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
            if (r.success) {
                location.reload();
            }
        },
        error: function (xhr, status) {
            console.log(status);
        },
    });
})


$('#momoform').submit(function (e) {
    if ($('#momo').val() == '') {
        alert(`請輸入要變更的密碼`);
        e.preventDefault();
        return ;
    }
    if (! confirm(`確定變更 MOMO密碼？`)) {
        e.preventDefault();
        $('#momo').val('');
        return ;
    }
});