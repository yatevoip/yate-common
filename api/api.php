<?php

/* api.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * JSON over HTTP API access library for Yate products
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2014-2019 Null Team
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

require_once("api_library.php");
@include_once("/usr/share/yate/scripts/yateversn.php");

$component_dir = "/usr/share/yate/api/";
$req_handlers = array();
$api_version = "unknown";

@include_once("api_version.php");
@include_once("api_config.php");
include_once("api_network.php");

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
	    if (preg_match('/^[[:alnum:]._-]+[:=][[:alnum:]._-]+$/',$level))
		$level = "/^(.*[^[:alnum:]._-])?$level([^[:alnum:]._-].*)?\$/";
	    else if (preg_match('/^\^.+\$$/',$level))
		$level = "/$level/";
	    else
		$level = '/[[:print:]]/';
    }
    $lines = getParam($params,"lines",50);
    if ($lines < 10)
	$lines = 10;
    else if ($lines > 10000)
	$lines = 10000;

    $start_log = getParam($params,"start_log","");
    if ($start_log && !isset($params["lines"]))
	$lines = FALSE;

    $stop_log = getParam($params,"stop_log","");

    for (;;) {
	$line = fgets($fh,4096);
	if ($line === false)
	    break;
	if ($stop_log && preg_match('/'.$stop_log.'/',$line)) {
	    $out[] = $line;
	    break;
	}
	if (preg_match('/^Supervisor \([0-9]+\) is starting/',$line))
	    $out = array();
	if ($start_log && preg_match('/'.$start_log.'/',$line))
	    $out = array();
	if (!preg_match($level,$line))
	    continue;
	while (ord(substr($line,strlen($line) - 1)) <= 0x20)
	    $line = substr($line,0,strlen($line) - 1);
	if ($lines!==FALSE) {
	    while (count($out) >= $lines)
		array_shift($out);
	}
	$out[] = $line;
    }

    pclose($fh);

    if (getParam($params,"inline"))
	return buildSuccess("node_logs",$out);
    return array(
	"_type" => "text/plain",
	"_body" => implode("\n",$out)
    );
}

function getNodeCDRs($node,$params)
{
    if (!preg_match('/^([[:alnum:]_-]+)$/',$node))
	return buildError(401,"Illegal node type");
    $conf = ("yate" == $node) ? "yate" : "yate-$node";
    $lines = getParam($params,"lines",50);
    if ($lines < 10)
	$lines = 10;
    else if ($lines > 10000)
	$lines = 10000;
    $out = shell_exec("sudo /var/www/html/api_asroot.sh get_node_cdrs $node $lines");
    if ($out === null)
	return buildError(501,"Cannot get CDRs for $conf");
    return array(
	"_type" => "text/tab-separated-values",
	"_file" => "$conf-cdr.tsv",
	"_body" => $out
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
    return (isset($obj["status"]) && isset($obj["status"]["operational"])
	    && $obj["status"]["operational"])
	|| (isset($obj["code"]) && (0 == $obj["code"]) && isset($obj["stats"]));
}

function getApiInfo($node, $request)
{
    global $component_dir, $api_version;
    if ((null === $node) || ("" == $node))
	$node = "common";
    $info = @file_get_contents("$component_dir/$node.json");
   
    if ($info)
	$info = json_decode($info,true);
    else if (function_exists("yaml_parse_file") && file_exists("$component_dir/$node.yaml"))
	$info = @yaml_parse_file("$component_dir/$node.yaml");
    else
	return buildError(401,"No documentation for node type '$node'.");
    if (!is_array($info))
	return buildError(401,"File format error for node '$node'.");
    if ("all" == $request)
	return buildSuccess("api_info",array("api_version" => $api_version, "requests" => $info));
    if (!$request) {
	$req = array();
	for ($i = 0; $i < count($info); $i++) {
	    $r = $info[$i];
	    if (!(isset($r["name"]) && isset($r["description"])))
		continue;
	    $req[$r["name"]] = $r["description"];
	}
	return buildSuccess("api_info",array("api_version" => $api_version, "descriptions" => $req));
    }
    for ($i = 0; $i < count($info); $i++) {
	$r = $info[$i];
	if ($r["name"] == $request)
	    return buildSuccess("api_info",array("api_version" => $api_version, "request" => $r));
    }
    if ("common" != $node) {
	$info = json_decode(@file_get_contents("$component_dir/common.json"),true);
	if (is_array($info)) {
	    for ($i = 0; $i < count($info); $i++) {
		$r = $info[$i];
		if ($r["name"] == $request)
		    return buildSuccess("api_info",array("api_version" => $api_version, "request" => $r));
	    }
	}
    }
    return buildError(401,"Request '$request' is not handled by $node.");
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
	case "get_host_name":
	    $res = function_exists("gethostname") ? gethostname() : false;
	    if (false !== $res)
		return buildSuccess("hostname",$res);
	    else
		return buildError(501,"Cannot retrieve host name");
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
	case "get_node_cdrs":
	    return getNodeCDRs($node,$params);
	case "get_node_config":
	    return getNodeConfig($node,getParam($params,"file",getParam($params,'$1')));
	case "node_restart":
	    return restartNode($node);
	case "node_reload":
	    return restartNode($node,"reload");
	case "info":
	    return getApiInfo($node,getParam($params,"request_info",getParam($params,"$1")));
    }
    if (preg_match('/^info[:_][A-Za-z]/',$req))
	return getApiInfo($node,substr($req,5));
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

checkRequest();

/* vi: set ts=8 sw=4 sts=4 noet: */
?>
