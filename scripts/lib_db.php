<?php

/* lib_db.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * DataBase access for Yate products
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2022-2023 Null Team
 *
 * This software is distributed under multiple licenses;
 * see the COPYING file in the main directory for licensing
 * information for this specific distribution.
 *
 * This use of this software may be subject to additional restrictions.
 * See the LEGAL file in the main directory for details.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 */

/**
    Database access connection
    Init params:
	Access (mysql):
	    host: Required
	    database: Required
	    user: Required
	    password: Required
	Access (sqlite):
	    database: Required database file path
	    initialize: Optional datatabase init sql script file
	fetch_field_names: boolean: Fetch rows using associative array with field names (true) or indexed array
	    default: true
	retry_busy: integer: Retry query on database busy or locked
	    default: mysql: 2, sqlite: 5
*/
class DbConn
{
    var $_dbtype;
    var $_connection;
    var $_fetchFieldNames;
    var $_lastError;
    var $_lastErrorApiCode;
    var $_access;
    var $_retryBusy;
    var $_retryBusyWait;

    static $debugType = NULL;
    static $debugQuery = 0;
    static $debugQueryOnError = 0;
    static $debugQueryResult = 0;
    static $debugName = "";

    // ER_LOCK_DEADLOCK 1213
    static $mysql_retryErrors = array(1213);
    // https://sqlite.org/rescode.html: (5) SQLITE_BUSY, (6) SQLITE_LOCKED
    static $sqlite_retryErrors = array(5,6);

    /**
     * Initialize object
     * @param $type Database type: mysql, sqlite
     * @param $params Initialize parameters. $GLOBALS will be used if NULL
     * @param $paramsPref Global parameter(s) prefix. Ignored if $params is given. Set it to NULL to skip initialize
     */
    function __construct($type,$params = NULL,$paramsPref = "")
    {
        $this->_dbtype = $type;
	$this->_connection = NULL;
	$this->_fetchFieldNames = true;
	$this->_lastError = NULL;
	$this->_lastErrorApiCode = 0;
	$this->_access = NULL;
	if ("mysql" == $this->_dbtype)
	    $this->_retryBusy = 2;
	else
	    $this->_retryBusy = 5;
	$this->_retryBusyWait = 100000;

	if (is_array($params))
	    $paramsPref = "";
	else if (NULL === $paramsPref)
	    return;
	else
	    $params = $GLOBALS;

	$paramPref = "$paramsPref";
	if ($paramPref)
	    $paramPref .= "_";
	if (isset($params["$paramPref" . "fetch_field_names"]))
	    $this->_fetchFieldNames = (true == $params["$paramPref" . "fetch_field_names"]);
	if (isset($params["$paramPref" . "retry_busy"])) {
	    $tmp = $params["$paramPref" . "retry_busy"];
	    if (is_numeric($tmp)) {
		$tmp = (int)$tmp;
		if ($tmp < 1)
		    $this->_retryBusy = 1;
		else if ($tmp > 10)
		    $this->_retryBusy = 10;
		else
		    $this->_retryBusy = $tmp;
	    }
	}

	$failMissing = array();
	if ("mysql" == $this->_dbtype)
	    $this->_access = array("host" => "","database" => "","user" => "","password" => "");
	else if ("sqlite" == $this->_dbtype) {
	    $this->_access = array("database" => "","initialize" => "", "readonly" => "");
	    $failMissing = array("database");
	}
	else
	    return;
	foreach ($this->_access as $n => $v) {
	    $tmp = "$paramsPref$n";
	    if (isset($params[$tmp]))
		$this->_access[$n] = $params[$tmp];
	    else if (in_array($n,$failMissing)) {
		$this->_access = NULL;
		break;
	    }
	}
    }

    /**
     * Escape a string using current database type
     * @param $value String to escape
     * @return Escaped string. 'NULL' if value is not set or database type is not set
     */
    function sqlStr($value)
    {
	if (!isset($value) || null === $value)
	    return "NULL";
	if ("mysql" == $this->_dbtype)
	    return "'" . mysqli_real_escape_string($this->_connection,$value) . "'";
	if ("sqlite" == $this->_dbtype)
	    return "'" . SQLite3::escapeString($value) . "'";
	return "NULL";
    }

    /**
     * Escape a numeric value
     * @param $value Value to escape
     * @return Escaped number. 'NULL' if value is not a number
     */
    function sqlNum($value)
    {
	if (is_numeric($value))
	    return (int)$value;
	return NULL;
    }

    /**
     * Escape current UNIX time (seconds) as numeric value
     */
    function sqlNumNow()
    {
	return $this->sqlNum(time());
    }

    /**
     * Escape a value
     * @param $value Value to escape
     * @return Escaped string or value itself if numeric. Call sqlStr() for non numeric values
     */
    function escape($value)
    {
	if ("integer" == gettype($value))
	    return $this->sqlNum($value);
	return $this->sqlStr($value);
    }

    /**
     * Query database
     * @param $query Query to execute
     * @param $oper Optional operation name to be set in error
     * @param $fetchByName Fetch result row in associative array by field name. Use DB config if NULL
     * @param $singleRow Return a single row
     * @param $noResult No result (row) is expected
     * @return False on database failure
     *   Success with no data (empty set or no result requested): NULL
     *   Single row requested: Non empty array with row 
     *   Multiple rows: Non empty array of rows
     */
    function query($query,$oper = NULL,$fetchByName = NULL,$singleRow = false,$noResult = false)
    {
	if (!$this->init())
	    return false;
	$this->logQuery($query);

	$res = false;
	$error = "";
	$runQ = $this->_retryBusy;
	while ($runQ-- > 0) {
	    if ("mysql" == $this->_dbtype)
		$res = mysqli_query($this->_connection,$query);
	    else if ("sqlite" == $this->_dbtype) {
		try {
		    if ($noResult)
			$res = $this->_connection->exec($query);
		    else
			$res = $this->_connection->query($query);
		}
		catch (Exception $e) { $res = false; }
	    }
	    else {
		$error = "unhandled DB type";
		break;
	    }
	    if ($res || !$this->isRetryBusyCode())
		break;
	    usleep($this->_retryBusyWait);
	}
    
	if ($res) {
	    if (true === $res || $noResult) {
		$this->logQuery($query,false,"none");
		return NULL;
	    }
	    if (NULL === $fetchByName)
		$fetchByName = $this->_fetchFieldNames;
	    $result = false;
	    if ("mysql" == $this->_dbtype) {
		$fetchByName = $fetchByName ? MYSQLI_ASSOC : MYSQLI_NUM;
		if (!$singleRow)
		    $res = $res->fetch_all($fetchByName);
		else
		    $res = $res->fetch_array($fetchByName);
		if (false !== $res)
		    $result = $res;
	    }
	    else if ("sqlite" == $this->_dbtype) {
		$fetchByName = $fetchByName ? SQLITE3_ASSOC : SQLITE3_NUM;
		try {
		    $result = $res->fetchArray($fetchByName);
		    if (!$singleRow && $result) {
			$result = array($result);
			while ($tmp = $res->fetchArray($fetchByName))
			    $result[] = $tmp;
		    }
		    if (!$result)
			$result = true;
		}
		catch (Exception $e) { $result = false; }
	    }
	    if ($result) {
		$this->logQuery($query,false,$result);
		return (is_array($result) && count($result)) ? $result : NULL;
	    }
	    $error = "result fetch failure";
	}
	else if (!$error) {
	    $error = $this->lastDbError();
	    if (!$error)
		$error = "";
	}

	$this->logQuery($query,$error);
	if (strlen($oper))
	    return $this->dbError($oper,$error,502);
	return $this->dbError("query",$error,502);
    }

    /**
     * Query database. No result is expected
     * @param $query Query to execute
     * @param $oper Optional operation name to be set in error
     * @param $failRetApiErr Return array describing the error on failure
     * @return Error not requested on failure: True on success, false on database failure
     *  Otherwise: NULL or array with error
     */
    function queryNoResult($query,$oper = NULL,$failRetApiErr = false)
    {
	$res = (NULL === $this->query($query,$oper,NULL,false,true));
	return $failRetApiErr ? ($res ? NULL : $this->lastErrorApi()) : $res;
    }

    /**
     * Query database. Return first found row
     * @param $query Query to execute
     * @param $oper Optional operation name to be set in error
     * @param $fetchByName Fetch result row in associative array by field name. Use DB config if NULL
     * @return Array with found row, NULL on empty result, false on database failure
     */
    function rowQuery($query,$oper = NULL,$fetchByName = NULL)
    {
	return $this->query($query,$oper,$fetchByName,true);
    }

    /**
     * Query database. Retrieve first field of first found row
     * @param $query Query to execute
     * @param $result Destination variable for result
     * @param $oper Optional operation name to be set in error
     * @return True if found, NULL if not found, false on database failure
     */
    function valQuery($query,&$result,$oper = NULL)
    {
	$res = $this->rowQuery($query,$oper,false);
	if (false === $res)
	    return false;
	if (!$res)
	    return NULL;
	$result = $res[0];
	return true;
    }

    /**
     * Query database. Build a select query from parameters
     * @param $query Query to execute
     * @param $oper Optional operation name to be set in error
     * @param $fetchByName Fetch result row in associative array by field name. Use DB config if NULL
     * @param $singleRow Return a single row
     * @param $noResult No result (row) is expected
     * @return False on database failure
     *   Success with no data (empty set or no result requested): NULL
     *   Single row requested: Non empty array with row 
     *   Multiple rows: Non empty array of rows
     */
    function select($table,$sel,$andCond,$oper = NULL,$fetchByName = NULL,$singleRow = false)
    {
	return $this->query("SELECT $sel FROM $table" . $this->prepareAndCondEq($cond,true),$params,$oper);
    }

    /**
     * Prepare an AND condition for field=value fields in '$cond' array
     * Escape values if needed
     * @param $cond Array with fields
     * @param $addClause Add a "WHERE" clause in front
     * @param $enclose Enclose condition(s) in paramthesis
     * @return String with condition. May be empty
     */
    function prepareAndCondEq($cond,$addClause = true,$enclose = true)
    {
	if (!is_array($cond))
	    return "";
	$s = "";
	foreach ($cond as $fld => $val) {
	    if ($s)
		$s .= " AND ";
	    else {
		if ($addClause)
		    $s = " WHERE ";
		if ($enclose)
		    $s .= "(";
	    }
	    $s .= "$fld=" . $this->escape($val);
	}
	if ($s && $enclose)
	    $s .= ")";
	return $s;
    }

    /**
     * Prepare a LIMIT clause from params
     * @param $params Parameters. Optional params: 'limit' -> integer, 'offset' -> integer
     * @return String with LIMIT/OFFSET. May be empty
     */
    function prepareLimit($params)
    {
	if (!isset($params["limit"]))
	    return "";
	$lim = $params["limit"];
	if (!(is_numeric($lim) && $lim))
	    return "";
	if (isset($params["offset"]))
	    return "LIMIT $lim OFFSET " . $params["offset"];
	return "LIMIT $lim";
    }

    function init()
    {
	if ($this->_connection)
	    return true;
	if (!$this->_access)
	    return $this->dbError("init","database access not configured");

	if ("mysql" == $this->_dbtype) {
	    if (!function_exists("mysqli_connect"))
		return $this->dbError("init","MySQL database access not available");
	    $this->_fetch_result_mode = MYSQLI_NUM;
	    $this->_connection = mysqli_connect($this->_access["host"],$this->_access["user"],
		$this->_access["password"],$this->_access["database"]);
	}
	else if ("sqlite" == $this->_dbtype) {
	    if (!class_exists("SQLite3"))
		return $this->dbError("init","SQLite database access not available");
	    $db = $this->_access["database"];
	    try {
		if ($this->_access["readonly"])
		    $this->_connection = new SQLite3($db,SQLITE3_OPEN_READONLY);
		else
		    $this->_connection = new SQLite3($db,SQLITE3_OPEN_READWRITE);
	    }
	    catch (Exception $e) {
		$this->_connection = false;
		// database does not exists => create it
		$ini = $this->_access["initialize"];
		if ($ini) {
		    $ini = file_get_contents($ini);
		    if (!$ini) {
			$this->close();
			return $this->dbError("create","failed to retrieve database structure",501);
		    }
		    try {
			$this->_connection = new SQLite3($db,SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
		    }
		    catch(Exception $e2) {
			$this->_connection = false;
			return $this->dbError("create","db '$db' (" . $e2->getMessage() . ")",501);
		    }
		    if (!$this->queryNoResult($ini)) {
			$e = $this->lastDbError();
			$this->close();
			if ($e)
			    $e = " ($e)";
			return $this->dbError("create","failed to create database structure$e",501);
		    }
		}
		else
		    $this->_connection = false;
	    }
	    // Enable exceptions to disable internal messages to be displayed
	    if ($this->_connection)
		$this->_connection->enableExceptions(true);
	}
	else
	    return $this->dbError("","type '". $this->_dbtype . "' init not handled");

	if ($this->_connection)
	    return true;

	$this->_connection = false;
	return $this->dbError("connect","",501);
    }

    function close()
    {
	if (!$this->_connection)
	    return;
	if ("mysql" == $this->_dbtype)
	    mysqli_close($this->_connection);
	else if ("sqlite" == $this->_dbtype)
	    $this->_connection->close();
	$this->_connection = NULL;
    }

    function dbError($oper,$str = "",$code = 0)
    {
	if (strlen($oper))
	    $oper = " $oper";
	if (strlen($str))
	    $str = " - $str";
	$this->_lastError = "Database$oper error$str.";
	$this->_lastErrorApiCode = $code;
	return false;
    }

    function lastError()
    {
	return $this->_lastError;
    }

    function lastErrorApi($code = null)
    {
	if ($this->_lastErrorApiCode)
	    $code = $this->_lastErrorApiCode;
	if ($code && is_numeric($code))
	    $code = 1 * $code;
	else
	    $code = $this->_lastErrorApiCode;
	if ($code && is_numeric($code))
	    $res = array("code" => $code);
	else
	    $res = array("code" => 502);
	if ($this->_lastError)
	    $res["message"] = $this->_lastError;
	return $res;
    }

    function lastDbError() {
	if ("mysql" == $this->_dbtype)
	    return mysqli_error($this->_connection);
	if ("sqlite" == $this->_dbtype)
	    return $this->_connection->lastErrorMsg();
	return "";
    }

    /**
     * Check if last connection error indicates a query should be retried
     */
    function isRetryBusyCode()
    {
	if ("mysql" == $this->_dbtype)
	    return in_array(mysqli_errno($this->_connection),DbConn::$mysql_retryErrors);
	if ("sqlite" == $this->_dbtype)
	    return in_array($this->_connection->lastErrorCode(),DbConn::$sqlite_retryErrors);
	return false;
    }

    function logQuery($query,$error = NULL,$result = NULL)
    {
	if (NULL === DbConn::$debugType)
	    DbConn::initDebugQuery();

	$level = 0;
	if (NULL === $error) {
	    if (DbConn::$debugQuery) {
		if ("Debug" == DbConn::$debugType)
		    $level = DbConn::$yate_debug_queries;
		else
		    $level = 10;
	    }
	}
	else if (false === $error) {
	    if (DbConn::$debugQueryResult) {
		if ("Debug" == DbConn::$debugType)
		    $level = DbConn::$debugQueryResult;
		else {
		    $level = 10;
		    $query = "Query '$query' succeeded";
		}
		if (NULL !== $result) {
		    if (is_array($result) || "object" == gettype($result))
			$query .= " - RES: " . json_encode($result);
		    else
			$query .= " - RES: $result";
		}
	    }
	}
	else if (DbConn::$debugQueryOnError >= 5) {
	    if (!$error)
		$error = $this->lastDbError();
	    $level = 5;
	    if ("Debug" != DbConn::$debugType)
		$query = "Query '$query' failed: $error";
	}
	if (!$level)
	    return;
	if ("Debug" == DbConn::$debugType)
	    Debug($level,$query);
	else {
	    $pref = date("Y-m-d_H:i:s",time());
	    if (DbConn::$debugName)
		$pref .= " <" . DbConn::$debugName . ">";
	    error_log("$pref $query");
	}
    }

    static function initDebugQuery($type = NULL,$debugQuery = NULL,$debugQueryError = NULL,
	$debugQueryResult = NULL)
    {
	if (!$type) {
	    if (function_exists("Debug"))
		$type = "Debug";
	    else
		$type = "error_log";
	    if (NULL === $debugQuery)
		$debugQuery = isset($GLOBALS["debugQuery"]) ? $GLOBALS["debugQuery"] : 0;
	    if (NULL === $debugQueryError)
		$debugQueryError = isset($GLOBALS["debugLevel"]) ? $GLOBALS["debugLevel"] : 0;
	    if (NULL === $debugQueryResult)
		$debugQueryResult = isset($GLOBALS["debugLevelDbResult"]) ?
		    $GLOBALS["debugLevelDbResult"] : 0;
	}

	DbConn::$debugType = $type;
	if (is_numeric($debugQuery) && $debugQuery > 0)
	    DbConn::$debugQuery = $debugQuery;
	else
	    DbConn::$debugQuery = 0;
	if (is_numeric($debugQueryError) && $debugQueryError >= 5)
	    DbConn::$debugQueryOnError = $debugQueryError;
	else
	    DbConn::$debugQueryOnError = 0;
	if (is_numeric($debugQueryResult) && $debugQueryResult > 0)
	    DbConn::$debugQueryResult = $debugQueryResult;
	else
	    DbConn::$debugQueryResult = 0;
    }

};

?>
