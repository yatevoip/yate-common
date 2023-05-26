#!/usr/bin/php -q
<?php
/**
 * node_starts.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2020-2023 Null Team
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

/*
  Notify Yate cluster management about machine start
 */

$notify_url = "";
$my_api_url = "";
$api_secret = "";
$api_version = "unknown";
$component_dir = "/usr/share/yate/api/";

@include_once("/var/www/html/api_version.php");
@include_once("/var/www/html/api_config.php");
include_once("/var/www/html/api_network.php");

function buildError($code,$message)
{
    return null;
}

function buildSuccess($name,$value)
{
    return $value;
}

function postJSON($url,$json,$key = "",$tout = 5)
{
    $curl = curl_init($url);
    if (false === $curl)
	return null;
    $opts = array(
	"Content-Type: application/json",
	"Accept: application/json,text/x-json,application/x-httpd-php"
    );
    if ("" != $key)
	$opts[] = "X-Authentication: $key";
    curl_setopt($curl,CURLOPT_POST,true);
    curl_setopt($curl,CURLOPT_POSTFIELDS,$json);
    curl_setopt($curl,CURLOPT_HTTPHEADER,$opts);
    curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);
    curl_setopt($curl,CURLOPT_CONNECTTIMEOUT,$tout);
    curl_setopt($curl,CURLOPT_TIMEOUT,$tout);
    curl_setopt($curl,CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl,CURLOPT_SSL_VERIFYHOST, false);
    $res = curl_exec($curl);
    if (false === $res) {
	curl_close($curl);
	return null;
    }
    $code = curl_getinfo($curl,CURLINFO_HTTP_CODE);
    $type = curl_getinfo($curl,CURLINFO_CONTENT_TYPE);
    curl_close($curl);
    if ($code < 200 || $code > 299)
	return null;
    if (false === strpos(strtolower($type),"json"))
	return null;
    return $res;
}

function listNodes()
{
    global $component_dir;

    $handle = @opendir($component_dir);
    if ($handle === false)
	return null;
    $nodes = array();
    while (false !== ($file = readdir($handle))) {
	if (substr($file,-4) != '.php')
	    continue;
	$name = substr($file,0,-4);
	if (!preg_match('/^([[:alnum:]_-]+)$/',$name))
	    continue;
	if (preg_match('/_version$/',$name))
	    continue;
	if (is_file("${component_dir}/${file}"))
	    $nodes[] = $name;
    }
    closedir($handle);
    return $nodes;
}

if ($_SERVER["argc"] >= 2)
    $notify_url = $_SERVER["argv"][1];
if (($_SERVER["argc"] >= 3) && ("-" != $_SERVER["argv"][2]))
    $my_api_url = $_SERVER["argv"][2];
if ($_SERVER["argc"] >= 4)
    $api_secret = $_SERVER["argv"][3];

if ("" != $notify_url) {
    $params = array(
	"api_version" => $api_version
    );
    if (true === $my_api_url || "auto" == $my_api_url) {
	$my_api_url = false;
	$tmp = parse_url($notify_url,PHP_URL_HOST);
	if (false !== $tmp) {
	    $tmp = gethostbyname($tmp);
	    if (preg_match('/^\[.*\]$/',$tmp))
		$tmp = substr($tmp,1,-1);
	    $s = socket_create(((false !== strpos($tmp,":")) ? AF_INET6 : AF_INET),SOCK_DGRAM,SOL_UDP);
	    if (socket_connect($s,$tmp,1024)) {
		if (socket_getsockname($s,$tmp)) {
		    if (false !== strpos($tmp,":"))
			$tmp = "[$tmp]";
		    $my_api_url = "http://$tmp/api.php";
		}
		socket_close($s);
	    }
	}
    }
    if ($my_api_url && ("--" != $my_api_url))
	$params["api_url"] = $my_api_url;
    $tmp = listNodes();
    if ($tmp)
	$params["node_types"] = $tmp;
    $tmp = function_exists("gethostname") ? gethostname() : false;
    if (false !== $tmp)
	$params["hostname"] = $tmp;
    $tmp = getNetAddress();
    if ($tmp)
	$params["net_address"] = $tmp;
    if (preg_match('!/api/v[1-9][0-9]*/!',$notify_url))
	postJSON($notify_url,json_encode($params),$api_secret);
    else {
	$req = array(
	    "request" => "node_starts",
	    "params" => $params
	);
	postJSON($notify_url,json_encode($req),$api_secret);
    }
}

/* vi: set ts=8 sw=4 sts=4 noet: */
?>
