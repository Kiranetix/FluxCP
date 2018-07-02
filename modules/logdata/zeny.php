<?php
if (!defined('FLUX_ROOT')) exit;

$title = Flux::message('ZenyLogTitle');

$sql_param_str = '';
$sql_params = array();

$charfrom = $params->get('from_char');
$charto = $params->get('to_char');
$datefrom = $params->get('from_date');
$dateto = $params->get('to_date');
$zeny_min = $params->get('zeny_min');
$zeny_max = $params->get('zeny_max');
$map = $params->get('map');
$type = array();
if ($params->get('type')) {
	$type = $params->get('type')->toArray();
	$type = array_keys($type);
}

if ($charfrom) {
	$sql_param_str .= '`src_id`=?';
	$sql_params[] = $charfrom;
}
if ($charto) {
	if ($sql_param_str)
		$sql_param_str .= " AND ";
	$sql_param_str .= '`char_id`=?';
	$sql_params[] = $charto;
}
if ($map) {
	if ($sql_param_str)
		$sql_param_str .= ' AND ';
	$sql_param_str .= '`map` LIKE ?';
	$sql_params[] = "%$map%";
}
if (count($type)) {
	if ($sql_param_str)
		$sql_param_str .= ' AND ';
	$sql_param_str .= '`type` IN ('.implode(',', array_fill(0, count($type), '?')).')';
	$sql_params = array_merge($sql_params, $type);
}
if ($datefrom || $dateto) {
	if ($sql_param_str)
		$sql_param_str .= ' AND ';
	if ($datefrom && $dateto) {
		$sql_param_str .= '`time` BETWEEN ? AND ?';
		$sql_params[] = $datefrom;
		$sql_params[] = $dateto;
	}
	else if ($datefrom && !$dateto) {
		$sql_param_str .= '`time` >= ?';
		$sql_params[] = $datefrom;
	}
	else {
		$sql_param_str .= '`time` <= ?';
		$sql_params[] = $dateto;
	}
}
if ($zeny_min || $zeny_max) {
	if ($sql_param_str)
		$sql_param_str .= ' AND ';
	if ($zeny_min && $zeny_max) {
		$sql_param_str .= '`amount` BETWEEN ? AND ?';
		$sql_params[] = $zeny_min;
		$sql_params[] = $zeny_max;
	}
	else if ($zeny_min && !$zeny_max) {
		$sql_param_str .= '`amount` >= ?';
		$sql_params[] = $zeny_min;
	}
	else {
		$sql_param_str .= '`amount` <= ?';
		$sql_params[] = $zeny_max;
	}
}

$sql = "SELECT COUNT(id) AS total FROM {$server->logsDatabase}.zenylog";
if ($sql_param_str)
	$sql .= " WHERE ".$sql_param_str;
$sth = $server->connection->getStatementForLogs($sql);
$sth->execute($sql_params);

$paginator = $this->getPaginator($sth->fetch()->total);
$paginator->setSortableColumns(array('time' => 'desc', 'char_id', 'src_id', 'type', 'amount', 'map'));

$sql = "SELECT time, char_id, src_id, type, amount, map FROM {$server->logsDatabase}.zenylog";
if ($sql_param_str)
	$sql .= " WHERE ".$sql_param_str;
$sql = $paginator->getSQL($sql);
$sth = $server->connection->getStatementForLogs($sql);
$sth->execute($sql_params);

$logs = $sth->fetchAll();

if ($logs) {
	$charIDs = array();
	$srcIDs  = array();
	$mobIDs  = array();
	$pickTypes = Flux::config('PickTypes');
	
	foreach ($logs as $log) {
		$charIDs[$log->char_id] = null;
		
		if ($log->type == 'M') {
			$mobIDs[$log->src_id] = null;
		}
		else {
			$srcIDs[$log->src_id] = null;
		}
		
		$log->pick_type = $pickTypes->get($log->type);
	}
	
	if ($charIDs || $srcIDs) {
		$charKeys = array_keys($charIDs);
		$srcKeys = array_keys($srcIDs);
		
		$sql  = "SELECT char_id, name FROM {$server->charMapDatabase}.`char` ";
		$sql .= "WHERE char_id IN (".implode(',', array_fill(0, count($charKeys) + count($srcKeys), '?')).")";
		$sth  = $server->connection->getStatement($sql);
		$sth->execute(array_merge($charKeys, $srcKeys));
		
		$ids = $sth->fetchAll();

		// Map char_id to name.
		foreach ($ids as $id) {
			if(array_key_exists($id->char_id, $charIDs)) {
				$charIDs[$id->char_id] = $id->name;
			}
			if(array_key_exists($id->char_id, $srcIDs)) {
				$srcIDs[$id->char_id] = $id->name;
			}
		}
	}
	
	require_once 'Flux/TemporaryTable.php';
	
	if ($mobIDs) {
		$mobDB      = "{$server->charMapDatabase}.monsters";
		$fromTables = array("{$server->charMapDatabase}.mob_db", "{$server->charMapDatabase}.mob_db2");
		$tempMobs   = new Flux_TemporaryTable($server->connection, $mobDB, $fromTables);

		$ids = array_keys($mobIDs);
		$sql = "SELECT ID, iName FROM {$server->charMapDatabase}.monsters WHERE ID IN (".implode(',', array_fill(0, count($ids), '?')).")";
		$sth = $server->connection->getStatement($sql);
		$sth->execute($ids);

		$ids = $sth->fetchAll();

		// Map id to name.
		foreach ($ids as $id) {
			$mobIDs[$id->ID] = $id->iName;
		}
	}
	
	foreach ($logs as $log) {
		if (array_key_exists($log->char_id, $charIDs)) {
			$log->char_name = $charIDs[$log->char_id];
		}
		
		if (($log->type == 'M') && array_key_exists($log->src_id, $mobIDs)) {
			$log->src_name = $mobIDs[$log->char_id];
		}
		elseif (array_key_exists($log->char_id, $srcIDs)) {
			$log->src_name = $srcIDs[$log->char_id];
		}
	}
}

?>
