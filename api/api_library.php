<?php

/* api_library.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * JSON over HTTP API utility library for Yate products
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2014-2023 Null Team
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

$logs_dir = "/var/log/json_api";
$logs_file = (is_writeable($logs_dir)) ? "$logs_dir/requests_log.txt" : null;

// These parameters may be altered by configuration
$max_requests = 3;
$log_status = false;
$api_secret = "";
$cors_origin = "*";

@include_once("/usr/share/yate/scripts/libyate.php");

function getParam($array,$name,$def = null)
{
    return isset($array[$name]) ? $array[$name] : $def;
}

function paramMissing($param)
{
    return ($param === null) || ("$param" == "");
}

function paramPresent($param)
{
    return ($param !== null) && ("$param" != "");
}

function buildSuccess($name = "",$value = null)
{
    $res = array("code" => 0);
    if ($name != "")
	$res[$name] = $value;
    return $res;
}

function buildError($code,$message)
{
    $res = array("code" => $code);
    if ($message != "" && $message !== null)
	$res["message"] = $message;
    return $res;
}

// Retrieve a string parameter
// $defVal: null: parameter is required
function getParamStr(&$params,$name,$defVal = "",$dict = null,$doTrim = true)
{
    $str = getParam($params,$name);
    if (null === $str) {
	if (null === $defVal)
	    return buildError(402,"Missing '$name' parameter.");
	return $defVal;
    }
    if (!is_string($str))
	return buildError(401,"Invalid '$name' parameter value - not a string.");
    if (is_array($dict) && !in_array($str,$dict))
	return buildError(402,"Unsupported '$name' parameter value '$str'.");
    return $doTrim ? trim($str) : $str;
}

// Retrieve an IP parameter
// $defVal: null: parameter is required
function getParamIp(&$params,$name,$v4 = true,$v6 = true,$defVal = null,$allAllowed = false)
{
    $ip = getParamStr($params,$name,$defVal);
    // Error ?
    if (is_array($ip))
	return $ip;
    if (!$ip) {
	if (null === $defVal)
	    return buildError(402,"Empty '$name' parameter.");
	return $defVal;
    }
    if ($v4) {
	$ok = ip2long($ip);
	if (is_numeric($ok)) {
	    return ($allAllowed || $ok) ? $ip
		: buildError(401,"Invalid '$name' parameter value '$ip' - all addresses not allowed.");
	}
    }
    if ($v6) {
	if ("::" == $ip) {
	    return $allAllowed ? $ip
		: buildError(401,"Invalid '$name' parameter value '$ip' - all addresses not allowed.");
	}
	if (preg_match('/^[[:xdigit:]]{1,4}:([[:xdigit:]:]+)?$/',$ip))
	    return $ip;
    }
    if ($v4 && $v6)
	return buildError(401,"Invalid '$name' parameter value '$ip' - not an IP address.");
    if ($v4)
	return buildError(401,"Invalid '$name' parameter value '$ip' - not an IPv4 address.");
    return buildError(401,"Invalid '$name' parameter value '$ip' - not an IPv6 address.");
}

// Retrieve a boolean parameter
// $defVal: null: parameter is required
function getParamBool(&$params,$name,$defVal = "")
{
    $val = getParam($params,$name);
    if (null === $val) {
	if (null === $defVal)
	    return buildError(402,"Missing '$name' parameter.");
	return $defVal;
    }
    if (!is_object($val)) {
	switch ($val) {
	    case true:
	    case "true":
	    case "yes":
		return true;
	    case false:
	    case "false":
	    case "no":
		return false;
	}
    }
    return buildError(401,"Invalid '$name' parameter value - not a boolean.");
}

// Retrieve a numeric parameter
// $defVal: null: parameter is required
// $clamp: false: return error if min/max are given and out of range
function getParamInt(&$params,$name,$defVal = null,$minVal = null,$maxVal = null,$clamp = false)
{
    $val = getParam($params,$name);
    if (null === $val) {
	if (null === $defVal)
	    return buildError(402,"Missing '$name' parameter.");
	return $defVal;
    }
    $num = false;
    if (is_numeric($val)) {
	if ("0" == $val)
	    $num = 0;
	else if (0 == ($num = intval($val)))
	    $num = false;
    }
    if (false === $num)
	return buildError(401,"Invalid '$name' parameter value - not an integer number.");
    if (is_numeric($minVal) && $num < $minVal) {
	if ($clamp)
	    return $minVal;
	return buildError(401,"Invalid '$name' parameter value '$val' - out of range.");
    }
    if (is_numeric($maxVal) && $num > $maxVal) {
	if ($clamp)
	    return $maxVal;
	return buildError(401,"Invalid '$name' parameter value '$val' - out of range.");
    }
    return $num;
}

function yateConnect($port,$track = "")
{
    global $yate_connected;
    if (isset($yate_connected))
	return $yate_connected;
    $yate_connected = class_exists("Yate") && Yate::Init(true,"127.0.0.1",$port,"",65536);
    if ($yate_connected) {
	Yate::Output(true);
	Yate::Debug(true);
	if ($track != "")
	    Yate::SetLocal("trackparam",$track);
	Yate::Watch("engine.timer");
    }
    return $yate_connected;
}

function yateRequest($port,$type,$request,$params,$recv,$wait = 5,$close = true,$maxReq = null,$name = null)
{
    global $max_requests;

    if (!($maxReq && is_numeric($maxReq)))
	$maxReq = $max_requests;
    if (strlen($name) > 0)
	$key = abs(crc32($name));
    else
	$key = $port ^ 0x79617465; // "yate"
    $sem = sem_get($key,abs($maxReq),0644,1);
    if (false === $sem)
	return buildError(201,"Semaphore creation failed");
    if (!sem_acquire($sem))
	return buildError(300,"Semaphore acquisition failed");
    $ret = yateRequestUnrestricted($port,$type,$request,$params,$recv,$wait,$close);
    sem_release($sem);
    if ($maxReq < 0)
	sem_remove($sem);
    return $ret;
}

function yateRequestUnrestricted($port,$type,$request,$params,$recv,$wait = 5,$close = true)
{
    global $yate_connected;
    if (!yateConnect($port))
	return buildError(200,"Cannot connect to Yate on port '$port'.");
    $msg = new Yate("api.request");
    $msg->SetParam("module","http_api");
    $msg->SetParam("type",$type);
    $msg->SetParam("operation",$request);
    if ($wait > 0)
	$msg->SetParam("timeout",1000 * $wait);

    if (is_array($recv)) {
	$msg->SetParam("received",$recv["recv"]);
	if (isset($recv["addr"]))
	    $msg->SetParam("address",$recv["addr"]);
	if (isset($recv["host"]))
	    $msg->SetParam("ip_host",$recv["host"]);
	if (isset($recv["port"]))
	    $msg->SetParam("ip_port",$recv["port"]);
	if (isset($recv["prot"]))
	    $msg->SetParam("protocol",$recv["prot"]);
	if (isset($recv["client"]))
	    $msg->SetParam("client",$recv["client"]);
    }
    else if (null !== $recv)
	$msg->SetParam("received",$recv);
    if ($params || is_array($params)) {
	$json = json_encode($params);
	// take into account an average expansion due to escaping
	$jlen = ceil(1.06 * strlen($json));
	if ($jlen > 7922)
	    Yate::SetLocal("bufsize",270 + $jlen);
	$msg->SetParam("json",$json);
    }
    $msg->Dispatch();
    $ev = true;
    $wait += time();
    while (time() <= $wait) {
	$ev = Yate::GetEvent();
	if ($ev === false)
	    break;
	if ($ev === true)
	    continue;
	if (($ev->type == "answer") && ($ev->name == $msg->name) && ($ev->id == $msg->id)) {
	    if ($close) {
		Yate::Quit(false);
		unset($yate_connected);
	    }
	    if (!$ev->handled)
		return buildError(200,"Request '$request' not handled by Yate.");
	    if ($ev->retval == "-") {
		$err = $ev->GetValue("error",400);
		if (preg_match('/^[+-]?[0-9]+$/',$err))
		    $err = (int) $err;
		return buildError($err,$ev->GetValue("reason"));
	    }
	    return buildSuccess($ev->retval,json_decode($ev->GetValue("json"),true));
	}
    }
    Yate::Quit(true);
    unset($yate_connected);
    return buildError(200,$ev ? "Timeout waiting for Yate response." : "Unexpectedly disconnected from Yate.");
}

function logRequest($addr,$inp,$out = null,$time = 0)
{
    global $logs_file;
    global $logs_dir;
    if (!$logs_file) {
	if (false !== $logs_file)
	    print "\n// Not writeable $logs_dir";
	return;
    }
    $day = date("Y-m-d");
    $file = str_replace(".txt","$day.txt",$logs_file);
    $fh = fopen($file, "a");
    if ($fh) {
	if ($out === null)
	    $out = "";
	else if (substr($out,0,1) == "{")
	    $out = "JsonOut: $out\n";
	else
	    $out = "DataOut: $out\n";
	if ($time)
	    $time = sprintf("Handled: %0.3f\n",microtime(true) - $time);
	else
	    $time = "";
	fwrite($fh, "------ " . date("Y-m-d H:i:s") . ", ip=$addr\nJson: $inp\n$out$time\n");
	fclose($fh);
    }
    else
	print "\n// Can't write to $file";
}

/**
 * Convert objects to associative arrays
 * Recursively apply function to array items
 * @param $d Input data
 * @return Array if given parameter is array or object, given parameter otherwise
 */
function apiStdToArray($d)
{
    if (is_object($d))
	$d = get_object_vars($d);
    if (is_array($d))
	return array_map(__FUNCTION__, $d);
    return $d;
}

function checkRequest($method = "POST")
{
    global $cors_origin,$log_status,$api_secret;
    if (("OPTIONS" == $_SERVER["REQUEST_METHOD"]) 
	    && isset($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"])
	    && ($_SERVER["HTTP_ACCESS_CONTROL_REQUEST_METHOD"] == $method)) {
	header("Access-Control-Allow-Origin: $cors_origin");
	header("Access-Control-Allow-Methods: $method");
	header("Access-Control-Allow-Headers: Content-Type");
	header("Content-Type: text/plain");
	exit;
    }
    $errcode = 0;
    $errtext = "";
    $orig = isset($_SERVER["HTTP_ORIGIN"]) ? $_SERVER["HTTP_ORIGIN"] : "*";
    if (("*" != $cors_origin) && (0 !== strpos($orig,$cors_origin))) {
	$errcode = 403;
	$errtext = "Access Forbidden";
    }
    else if (("" != $api_secret) && !isset($_SERVER["HTTP_X_AUTHENTICATION"])) {
	$errcode = 401;
	$errtext = "API Authentication Required";
    }
    else if (("" != $api_secret) && ($_SERVER["HTTP_X_AUTHENTICATION"] != $api_secret)) {
	$errcode = 403;
	$errtext = "API Authentication Rejected";
    }
    else {
	$ctype = isset($_SERVER["CONTENT_TYPE"]) ? strtolower($_SERVER["CONTENT_TYPE"]) : "";
	$ctype = preg_replace('/[[:space:]]*;.*$/',"",$ctype);
	$yaml = false;
	switch ($ctype) {
	    case "application/json":
	    case "text/x-json":
		if ("POST" == $_SERVER["REQUEST_METHOD"]) {
		    $json_in = file_get_contents('php://input');
		    $inp = json_decode($json_in,true);
		    if ($inp === null) {
			$errcode = 415;
			$errtext = "Unparsable JSON content";
		    }
		}
		else {
		    $errcode = 405;
		    $errtext = "Method Not Allowed";
		}
		break;
	    case "application/yaml":
	    case "application/x-yaml":
	    case "text/yaml":
	    case "text/x-yaml":
	    case "text/vnd.yaml":
		if ("POST" == $_SERVER["REQUEST_METHOD"]) {
		    if (function_exists("yaml_parse")) {
			$yaml = true;
			$inp = yaml_parse(file_get_contents('php://input'));
			if (false === $inp) {
			    $errcode = 415;
			    $errtext = "Unparsable YAML content";
			}
			else if (null === $inp)
			    $json_in = "{ }";
			else
			    $json_in = json_encode($inp);
		    }
		    else {
			$errcode = 415;
			$errtext = "Unsupported Media Type";
		    }
		}
		else {
		    $errcode = 405;
		    $errtext = "Method Not Allowed";
		}
		break;
	    case "application/x-www-form-urlencoded":
	    case "":
		$vars = null;
		switch ($_SERVER["REQUEST_METHOD"]) {
		    case "GET":
			$vars = $_GET;
			break;
		    case "POST":
			$vars = $_POST;
			break;
		    default:
			$errcode = 405;
			$errtext = "Method Not Allowed";
		}
		if (null !== $vars) {
		    $inp = array();
		    $pre = "";
		    if (isset($_SERVER["PATH_INFO"])) {
			$v = $_SERVER["PATH_INFO"];
			if (preg_match('/^\/yaml(\/.*)?$/',$v)) {
			    $yaml = true;
			    $v = substr($v,5);
			}
			else if (preg_match('/^\/json(\/.*)?$/',$v)) {
			    $yaml = false;
			    $v = substr($v,5);
			}
			if (preg_match('/^\/echo(\/.*)?$/',$v)) {
			    $pre = "echo:";
			    $v = substr($v,5);
			}
			if (preg_match('/^\/([a-z][[:alnum:]_]*)(\/[[:alnum:]_-]*)?(\/.*)?$/',$v,$match)) {
			    $inp["request"] = $pre . $match[1];
			    if (isset($match[2]) && (strlen($match[2]) > 1))
				$inp["node"] = substr($match[2],1);
			    if (isset($match[3]) && (strlen($match[3]) > 1)) {
				$v = substr($match[3],1);
				while ("/" == substr($v,-1))
				    $v = substr($v,0,-1);
				$v = explode('/',$v);
				for ($k = 0; $k < count($v); $k++) {
				    if (!$k)
					$inp["params"] = array();
				    $inp["params"]['$' . ($k + 1)] = $v[$k];
				}
			    }
			}
		    }
		    foreach ($vars as $k => $v) {
			switch ($k) {
			    case "request":
				$v = $pre . $v;
				// fall through
			    case "node":
				$inp[$k] = $v;
				break;
			    default:
				switch ($v) {
				    case "null":
					$v = null;
					break;
				    case "true":
					$v = true;
					break;
				    case "false":
					$v = false;
					break;
				    case "0":
					// evaluation protection for 00000000e3 which is == 0x10^3 == 0*1000 == "0" 
					if ($v === "0") {
					    $v = 0;
					    break;
					}
					// otherwise let it pass to default:
				    default:
					if (preg_match('/^-?[1-9][0-9]*$/',$v))
					    $v = (int) $v;
				}
				if ("yaml" == $k) {
				    $yaml = !!$v;
				    break;
				}
				$o = &$inp;
				$p = "params";
				for (;;) {
				    if (!(isset($o[$p]) && is_array($o[$p])))
					$o[$p] = array();
				    $o = &$o[$p];
				    $i = strpos($k,"/");
				    if ($i <= 0)
					break;
				    $p = substr($k,0,$i);
				    $k = substr($k,$i + 1);
				}
				$o[$k] = $v;
			}
		    }
		    if (count($inp))
			$json_in = json_encode($inp);
		    else {
			$errcode = 400;
			$errtext = "Empty Request";
		    }
		    break;
		}
		if ($errcode)
		    break;
		// fall through
	    default:
		$errcode = 415;
		$errtext = "Unsupported Media Type";
	}

	if (!($errcode || function_exists("processRequest"))) {
	    $errcode = 500;
	    $errtext = "Internal server error";
	}
	if (!$errcode) {
	    if (isset($_SERVER["HTTP_ORIGIN"]))
		header("Access-Control-Allow-Origin: $orig");
	    $serv = "unknown";
	    $recv = gmdate(DATE_RFC850);
	    if (isset($_SERVER['REMOTE_ADDR'])) {
		$serv = $_SERVER['REMOTE_ADDR'];
		$recv .= " from $serv";
		$recv = array("recv" => $recv, "host" => $serv);
		if (isset($_SERVER['REMOTE_PORT'])) {
		    $recv["port"] = $port = $_SERVER['REMOTE_PORT'];
		    if (false !== strpos($serv,":"))
			$recv["addr"] = "[$serv]:$port";
		    else
			$recv["addr"] = "$serv:$port";
		}
		else
		    $recv["addr"] = $serv;
		$recv["prot"] = isset($_SERVER['HTTPS']) ? "HTTPS" : "HTTP";
		if (isset($_SERVER['HTTP_USER_AGENT']))
		    $recv["client"] = $_SERVER['HTTP_USER_AGENT'];
	    }
	    $time = microtime(true);
	    $out = processRequest($inp,$recv,$json_in);
	    if (isset($out["_type"])) {
		$type = $out["_type"];
		header("Content-type: $type");
		if (isset($out["_file"])) {
		    header("Content-Disposition: attachment; filename=\"" . $out["_file"] . "\"");
		    $type .= "; file=" . $out["_file"];
		}
		if (isset($out["_body"])) {
		    print $out["_body"];
		    $type .= "; length=" . strlen($out["_body"]);
		}
		else if (isset($out["_stream"])) {
		    fpassthru($out["_stream"]);
		    fclose($out["_stream"]);
		    $type .= "; stream=handle";
		}
		else if (isset($out["_process"])) {
		    fpassthru($out["_process"]);
		    pclose($out["_process"]);
		    $type .= "; stream=process";
		}
		logRequest($serv,$json_in,$type,$time);
	    }
	    else if ($yaml && defined('YAML_UTF8_ENCODING')) {
		header("Content-type: text/x-yaml");
		$log = $log_status
		    || (isset($inp["params"]) && is_array($inp["params"]) && count($inp["params"]))
		    || !(function_exists("isOperational") && isOperational($out));
		print yaml_emit($out,YAML_UTF8_ENCODING);
		if ($log)
		    logRequest($serv,$json_in,($out === null) ? "{ }" : json_encode($out),$time);
	    }
	    else {
		header("Content-type: application/json");
		$log = $log_status
		    || (isset($inp["params"]) && is_array($inp["params"]) && count($inp["params"]))
		    || !(function_exists("isOperational") && isOperational($out));
		$json_out = ($out === null) ? "{ }" : json_encode($out);
		print $json_out;
		if ($log)
		    logRequest($serv,$json_in,$json_out,$time);
	    }
	    exit;
	}
    }
    header("HTTP/1.1 $errcode $errtext");
?><html>
<head>
<title><?php print($errtext); ?></title>
</head>
<body>
<h1><?php print($errtext); ?></h1>
Expecting <b><?php print($method); ?></b> with type <b>application/json</b> from <b><?php print($cors_origin); ?></b>
</body>
</html>
<?php
    exit;
}

/* vi: set ts=8 sw=4 sts=4 noet: */
?>
