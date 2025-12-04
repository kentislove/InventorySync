<?php

$GLOBALS['db_name'] = "yahoo";
$GLOBALS['db_user'] = "demo";
$GLOBALS['db_pass'] = "demo";
try {
    $GLOBALS['dbh'] = new PDO('mysql:host=localhost;dbname='.$GLOBALS['db_name'].';charset=utf8', $GLOBALS['db_user'], $GLOBALS['db_pass'], [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // PDO::ATTR_PERSISTENT => true,
        // PDO::ATTR_EMULATE_PREPARES => false,
        // PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $GLOBALS['dbh']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // echo "<pre>" .print_r($e->getMessage(), true). "</pre>";
    die('nono');
}


/**
 * 取得 PDO SQL 語句
 * @param string $table 更新的 table 名稱
 * @param array $upds 更新資料的陣列, eg: $upds[表單欄位名稱] = '更新的值'
 * @param string,int $id 更新的索引
 * @param string $field 索引欄位名稱, 預設 ID
 * @param array $whereArray 更多配對索引條件, eg $whereArray[表單欄位名稱]  = '索引值'
 * 
 * $inputs = [
 *  'TEMPLATE' => $_POST['id'],
 *  'TEMPLATE1' => $layout['MOLD_1'],
 * ];
 * $tmp = pdo_update_sql('SITE_CONFIG', $inputs);
 * $sth = $GLOBALS['dbh']->prepare($tmp['sql']);
 * $sth->execute($tmp['params']);
 */
function pdo_update_sql($table, $upds, $id = false, $field = 'id', $whereArray = [])
{
    $params = [];
    foreach ($upds as $k => $v) {
        $params[':' . $k] = $v ?? '';
    }

    $sql = 'update ' . $table . ' set ';
    foreach ($upds as $k => $v) {
        $sql .= '`'.$k . '` = :' . $k . ',';
    }
    $sql = rtrim($sql, ',') . ' ';

    // 其他的 where 條件
    if (! empty($whereArray)) {
        if (is_array($whereArray)) {
            $sql .= 'where 1 ';
            foreach ($whereArray as $k => $v) {
                $params[':' . $k] = $v;
                $sql .= 'and `' . $k . '` = :' . $k . ' ';
            }
            $sql = rtrim($sql, ',');
        } else {
            // 也可以直接下 where ID = 15 之類
            $sql .= ' ' . $whereArray . ' ';
        }
    }

    // where ID 條件
    if ($id !== false) {
        $params[':' . $field] = $id;

        if (strpos($sql, 'where ') !== false) {
            $sql .= 'and `' . $field . '` = :' . $field . ' ';
        } else {
            $sql .= 'where `' . $field . '` = :' . $field . ' ';
        }        
    }

    return [
        'sql'    => $sql,
        'params' => $params,
    ];
}


/** 取得 PDO SQL 語句 */
function pdo_insert_sql($table, $adds)
{
    $params = [];
    foreach ($adds as $k => $v) {
        $params[':' . $k] = $v ?? '';
    }
    $sql  = 'insert into ' . $table . ' (`' . implode('`,`', array_keys($adds)) . '`) values (' . implode(',', array_keys($params)) . ') ';
    return [
        'sql'    => $sql,
        'params' => $params,
    ];
}


function sql_delete($tbName, $where)
{	
	// 檢查原本有沒有資料
	$sth = $GLOBALS['dbh']->prepare('select * from ' . $tbName . ' ' . $where);
	$sth->execute();
	if ($sth->rowCount() == 0) { // 如果本來就沒資料
		return true;
	} else { // 如果刪除失敗
		$sth = $GLOBALS['dbh']->prepare('delete from ' . $tbName . ' ' . $where);
		$sth->execute();
		return $sth->rowCount();
	}
}