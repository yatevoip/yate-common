#!/usr/bin/php -q
<?php
/**
 * node_starts.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2020 Null Team
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

switch ($_SERVER["argc"]) {
    case 4:
	$api_secret = $_SERVER["argv"][3];
	// fall through
    case 3:
	$my_api_url = $_SERVER["argv"][2];
	// fall through
    case 2:
	$notify_url = $_SERVER["argv"][1];
}


if ("" != $notify_url) {
    $params = array(
	"api_version" => $api_version
    );
    if ("" != $my_api_url && "-" != $my_api_url)
	$params["api_url"] = $my_api_url;
    $tmp = getNetAddress();
    if ($tmp)
	$params["net_address"] = $tmp;
    $req = array(
	"request" => "node_starts",
	"params" => $params
    );
    postJSON($notify_url,json_encode($req),$api_secret);
}

/* vi: set ts=8 sw=4 sts=4 noet: */
?>