<?php

/* api.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * JSON over HTTP API access library for Yate products
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2014-2017 Null Team
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

@include_once("/usr/share/yate/scripts/libyate.php");
@include_once("/usr/share/yate/scripts/yateversn.php");

$component_dir = "/usr/share/yate/api/";
$req_handlers = array();
$api_version = "unknown";
$logs_dir = "/var/log/json_api";
$logs_file = (is_writeable($logs_dir)) ? "$logs_dir/requests_log.txt" : null;
// these may be altered in api_config.php
$max_requests = 3;
$log_status = false;
$api_secret = "";
$cors_origin = "*";

@include_once("api_version.php");
@include_once("api_config.php");
include_once("api_network.php");

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

function yateRequest($port,$type,$request,$params,$recv,$wait = 5,$close = true)
{
    global $max_requests;
    $key = $port ^ 0x79617465; // "yate"
    $sem = sem_get($key,abs($max_requests),0644,1);
    if (false === $sem)
	return buildError(201,"Semaphore creation failed");
    if (!sem_acquire($sem))
	return buildError(300,"Semaphore acquisition failed");
    $ret = yateRequestUnrestricted($port,$type,$request,$params,$recv,$wait,$close);
    sem_release($sem);
    if ($max_requests < 0)
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
    }
    else
	$msg->SetParam("received",$recv);
    if ($params) {
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
		if (is_int(1 * $err))
		    $err = 1 * $err;
		return buildError($err,$ev->GetValue("reason"));
	    }
	    return buildSuccess($ev->retval,json_decode($ev->GetValue("json"),true));
	}
    }
    Yate::Quit(true);
    unset($yate_connected);
    return buildError(200,$ev ? "Timeout waiting for Yate response." : "Unexpectedly disconnected from Yate.");
}

function addHandler($handler, $name = null)
{
    global $req_handlers;
    if (is_callable($handler)) {
	$res = true;
	if (null !== $name) {
	    if (isset($req_handlers[$name]))
		$res = $req_handlers[$name];
	    $req_handlers[$name] = $handler;
	}
	else
	    $req_handlers[] = $handler;
	return $res;
    }
    return false;
}

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

function getVersion($node)
{
    global $req_handlers, $yate_version;
    foreach($req_handlers as $handler) {
	$ver = $handler("get_version",null,null,$node);
	if (isset($ver["version"]))
	    return $ver["version"];
    }
    if (("yate" == $node) && isset($yate_version))
	return $yate_version;
    return null;
}

function loadNode($type)
{
    global $component_dir;
    $file = "${component_dir}/${type}.php";
    return (is_file($file) && !!@include_once($file)) || ("yate" == $type);
}

function loadNodes($prefix = null)
{
    global $component_dir;
    $handle = @opendir($component_dir);
    if ($handle === false)
	return 0;
    $cnt = 0;
    while (false !== ($file = readdir($handle))) {
	if (substr($file,-4) != '.php')
	    continue;
	$name = substr($file,0,-4);
	if ((null !== $prefix) && ("" != $prefix)) {
	    if (substr($name,0,strlen($prefix)) != $prefix)
		continue;
	    $name = substr($name,strlen($prefix));
	}
	if (!preg_match('/^([[:alnum:]_-]+)$/',$name))
	    continue;
	$file = "${component_dir}/${file}";
	if (!is_file($file))
	    continue;
	if (@include_once($file))
	    $cnt++;
    }
    closedir($handle);
    return $cnt;
}

function getOemSerial()
{
    $file = "/etc/sysconfig/oem";
    if (!is_file($file))
	return buildError(404,"File not found: $file");
    $fh = @fopen($file,"r");
    if (false === $fh)
	return buildError(501,"Cannot open for reading: $file");
    for (;;) {
	$line = fgets($fh,4096);
	if ($line === false)
	    break;
	if (preg_match('/^ *SERIAL *= *"?([[:alnum:]._-]+)"? *$/',$line,$match))
	    return buildSuccess("serial",$match[1]);
    }
    pclose($fh);
    return buildSuccess("serial",null);
}

function getNodeConfig($node,$file)
{
    if (!preg_match('/^([[:alnum:]_-]+)$/',$node))
	return buildError(401,"Illegal node type");
    if (("" == $file) || (null === $file))
	$file = "";
    else if (!preg_match('/^([[:alnum:]_.-]+)$/',$file))
	return buildError(401,"Illegal file name");
    $conf = ("yate" == $node) ? "yate" : "yate/$node";
    $dir = "/etc/$conf";
    if (!is_file("/usr/share/yate/api/${node}_asroot.sh")) {
	if (!is_dir($dir))
	    return buildError(404,"Directory not found: $dir");
	if (("" != $file) && !is_file("$dir/$file"))
	    return buildError(404,"File not found: $dir/$file");
    }

    if ("" != $file)
	$file = " $file";
    $out = shell_exec("sudo /var/www/html/api_asroot.sh get_node_config $node$file");
    if ($out === null)
	return buildError(501,"Cannot get configuration from: $dir$file");
    $conf = ("yate" == $node) ? "yate" : "yate-$node";
    $file = ("" == $file) ? "config.tar.gz" : substr($file,1);
    return array(
	"_type" => "application/octet-stream",
	"_file" => "$conf.$file",
	"_body" => $out
    );
}

function getNodeLogs($node,$params)
{
    if (!preg_match('/^([[:alnum:]_-]+)$/',$node))
	return buildError(401,"Illegal node type: $node");
    $serv = ("yate" == $node) ? "yate" : "yate-$node";
    $log = "/var/log/$serv";
    if (!(is_file($log) || is_file("/usr/share/yate/api/${node}_asroot.sh")))
	return buildError(404,"Log file not found: $log");

    $out = array();
    $fh = popen("sudo /var/www/html/api_asroot.sh get_node_logs $node","r");
    if ($fh === false)
	return buildError(501,"Cannot retrieve logs from: $log");

    $level = getParam($params,"level",getParam($params,'$1'));
    switch (strtoupper("$level")) {
	case "10":
	case "ALL":
	    $level = '/^([^<]+ )?<([^ ]+:)?(ALL|INFO|CALL|NOTE|MILD|WARN|STUB|CONF|CRIT|GOON|TEST|FAIL)>/';
	    break;
	case "9":
	case "INFO":
	    $level = '/^([^<]+ )?<([^ ]+:)?(INFO|CALL|NOTE|MILD|WARN|STUB|CONF|CRIT|GOON|TEST|FAIL)>/';
	    break;
	case "8":
	case "CALL":
	    $level = '/^([^<]+ )?<([^ ]+:)?(CALL|NOTE|MILD|WARN|STUB|CONF|CRIT|GOON|TEST|FAIL)>/';
	    break;
	case "7":
	case "NOTE":
	    $level = '/^([^<]+ )?<([^ ]+:)?(NOTE|MILD|WARN|STUB|CONF|CRIT|GOON|TEST|FAIL)>/';
	    break;
	case "6":
	case "MILD":
	    $level = '/^([^<]+ )?<([^ ]+:)?(MILD|WARN|STUB|CONF|CRIT|GOON|TEST|FAIL)>/';
	    break;
	case "5":
	case "WARN":
	    $level = '/^([^<]+ )?<([^ ]+:)?(WARN|STUB|CONF|CRIT|GOON|TEST|FAIL)>/';
	    break;
	default:
	    $level = '/[[:print:]]/';
    }
    $lines = getParam($params,"lines",50);
    if ($lines < 10)
	$lines = 10;
    else if ($lines > 10000)
	$lines = 10000;
    for (;;) {
	$line = fgets($fh,4096);
	if ($line === false)
	    break;
	if (preg_match('/^Supervisor \([0-9]+\) is starting/',$line))
	    $out = array();
	if (!preg_match($level,$line))
	    continue;
	while (ord(substr($line,strlen($line) - 1)) <= 0x20)
	    $line = substr($line,0,strlen($line) - 1);
	while (count($out) >= $lines)
	    array_shift($out);
	$out[] = $line;
    }
    pclose($fh);

    return array(
	"_type" => "text/plain",
	"_body" => implode("\n",$out)
    );
}

function restartNode($node,$oper = "restart")
{
    if (!preg_match('/^([[:alnum:]_-]+)$/',$node))
	return buildError(401,"Illegal node type");

    $out = shell_exec("sudo /var/www/html/api_asroot.sh node_$oper $node");
    if ($out === null)
	return buildError(501,"Could not $oper node $node");
    return buildSuccess("${oper}ed",$node);
}

function serviceState($node,$quiet = false)
{
    if (!preg_match('/^([[:alnum:]_-]+)$/',$node))
	return null;
    $quiet = $quiet ? "_quiet" : "";
    return shell_exec("/var/www/html/api_asroot.sh node_service$quiet $node");
}

function isOperational($obj)
{
    return isset($obj["status"]) && isset($obj["status"]["operational"])
	&& $obj["status"]["operational"];
}

function processRequest($json,$recv)
{
    global $req_handlers;
    global $api_version, $yate_version;
    $req = getParam($json,"request");
    if (paramMissing($req))
	return buildError(402,"Missing 'request' parameter.");
    if (preg_match('/^echo[:_][A-Za-z]/',$req)) {
	$json["request"] = substr($req,5);
	return $json;
    }
    $node = getParam($json,"node");
    if ((null !== $node) && ("" != $node) && !preg_match('/^[[:alnum:]_-]+$/',$node))
	return buildError(401,"Illegal 'node' parameter.");
    $params = getParam($json,"params");
    switch ($req) {
	case "get_api_version":
	    return buildSuccess("api_version",$api_version);
	case "get_oem_serial":
	    return getOemSerial();
	case "get_net_address":
	    return getNetAddress(getParam($params,"filtered",true));
	case "get_node_type":
	    if (!loadNodes())
		return buildError(201,"No node plugin is installed.");
	    $list = array();
	    foreach($req_handlers as $handler) {
		$res = $handler($req,$json,$recv,null);
		// some equipment might build nodes themself. Ex: satsite
		// in this case merge to list instead of pushing to it
		if (is_array($res) && !isset($res[0]))
		    $list[] = $res;
		elseif (is_array($res))
		    $list = array_merge($list,$res);
	    }
	    return buildSuccess("node_type",$list);
	case "get_node_logs":
	    return getNodeLogs($node,$params);
	case "get_node_config":
	    return getNodeConfig($node,getParam($params,"file",getParam($params,'$1')));
	case "node_restart":
	    return restartNode($node);
	case "node_reload":
	    return restartNode($node,"reload");
    }
    if (paramMissing($node)) {
	if (!loadNodes())
	    return buildError(201,"No node plugin is installed.");
    }
    else if (!loadNode($node))
	return buildError(401,"Unsupported node type '$node'.");
    $res = null;
    foreach($req_handlers as $handler) {
	$res = $handler($req,$json,$recv,$node);
	if ($res !== null)
	    break;
    }
    if ((null === $res) && ("yate" == $node) && ("get_node_status" == $req))
	$res = array("code" => 0, "status" => array("level" => "INFO", "state" => "Unknown"));
    if ($res !== null) {
	if ("get_node_status" == $req) {
	    $serv = serviceState($node,isOperational($res));
	    if (null !== $serv) {
		if (isset($res["status"]) && is_array($res["status"]))
		    $res["status"]["service"] = $serv;
		else if (isset($res["code"]) && isset($res["message"]) && $res["code"])
		    $res["message"] .= "\n$serv";
		else
		    $res["service"] = $serv;
	    }
	    $ver = getVersion($node);
	    if (null !== $ver)
		$res["version"] = $ver;
	}
	else if ("query_stats" == $req && isset($res["stats"]["engine"])  && is_array($res["stats"]["engine"])) {
	    $ver = getVersion($node);
	    if (null !== $ver)
		$res["stats"]["engine"]["node_version"] = $ver;
	    if (isset($yate_version))
		$res["stats"]["engine"]["yate_version"] = $yate_version;
	}
	return $res;
    }
    $node = paramMissing($node) ? "any node" : "node '$node'";
    return buildError(401,"Request '$req' not handled by $node.");
}

function logRequest($addr,$inp,$out = null)
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
	else
	    $out = "JsonOut: $out\n";
	fwrite($fh, "------ " . date("Y-m-d H:i:s") . ", ip=$addr\nJson: $inp\n$out\n");
	fclose($fh);
    }
    else
	print "\n// Can't write to $file";
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
	switch ($ctype) {
	    case "application/json":
	    case "text/x-json":
		if ("POST" == $_SERVER["REQUEST_METHOD"]) {
		    $json_in = file_get_contents('php://input');;
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
					$v = 0;
					break;
				    default:
					if (preg_match('/^-?[1-9][0-9]*$/',$v))
					    $v = 1 * $v;
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
	    }
	    $out = processRequest($inp,$recv);
	    if (isset($out["_type"])) {
		header("Content-type: " . $out["_type"]);
		if (isset($out["_file"]))
		    header("Content-Disposition: attachment; filename=\"" . $out["_file"] . "\"");
		print $out["_body"];
		logRequest($serv,$json_in);
	    }
	    else {
		header("Content-type: application/json");
		$log = $log_status || !isOperational($out);
		$json_out = ($out === null) ? "{ }" : json_encode($out);
		print $json_out;
		if ($log)
		    logRequest($serv,$json_in,$json_out);
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

checkRequest();

/* vi: set ts=8 sw=4 sts=4 noet: */
?>
