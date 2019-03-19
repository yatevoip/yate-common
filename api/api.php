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

checkRequest();

/* vi: set ts=8 sw=4 sts=4 noet: */
?>
