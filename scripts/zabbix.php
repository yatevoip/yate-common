#!/usr/bin/php -q
<?php
/**
 * zabbix.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2017 Null Team
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
  Zabbix (TM) active agent for Yate based products
  Uses the Zabbix version 1 JSON to push requested data to a Zabbix server
  For the Zabbix software see http://www.zabbix.com/

  To use add in extmodule.conf:

  [scripts]
  zabbix.php=server
    or
  zabbix.php=server:port
    or
  zabbix.php=server:port,server:port,...

  For IPv6 servers you need to specify the IP address, not hostname, like this:
  zabbix.php=fc00::1,[fc00::2]:12345

  In Zabbix the server must be named: CONFIGNAME - NODENAME
  where CONFIGNAME is the base name of the main config file (yate, yate-sdr, etc.)
  and NODENAME is the configured node or machine name (localhost, myserver.dom.ain, etc.)
  Example: yate-sdr - bts01.example.com

  All item names are of the form yate.SECTION.KEY with the same structure as query_stats
  response in the Yate's JSON API
 */

require_once("libyate.php");

Yate::Init();
// Comment the next line to get output only in logs, not in rmanager
Yate::Output(true);
// Uncomment the next line to get debugging details by default
//Yate::Debug(true);

class ZabbixServer
{
    public static $list = array();
    public static $hostname;
    public static $iotimeout = 5;

    private function ZabbixServer($host,$port)
    {
	$this->host = $host;
	$this->addr = (false !== strpos($host,":")) ? "[$host]:$port" : "$host:$port";
	$this->info = "Zabbix server " . $this->addr;
	$this->data = -1;
	$this->delay = 60;
	Yate::Debug("Created new " . $this->info);
    }

    function connect()
    {
	$this->disconnect();
	$skt = @stream_socket_client("tcp://" . $this->addr,$errno,$errstr,ZabbixServer::$iotimeout);
	if (false === $skt) {
	    Yate::Output("Could not connect to " . $this->info . ": $errstr ($errno)");
	    return false;
	}
	stream_set_timeout($skt,ZabbixServer::$iotimeout);
	Yate::Debug("Connected to " . $this->info);
	$this->socket = $skt;
	return true;
    }

    function disconnect()
    {
	if (!isset($this->socket))
	    return;
	fclose($this->socket);
	unset($this->socket);
	unset($this->header);
	unset($this->length);
    }

    function reset($force) {
	$when = time();
	if ($force || !isset($this->checks)) {
	    $this->disconnect();
	    $this->data = -1;
	    $this->delay = 60;
	    $this->timeout = $when;
	    unset($this->checks);
	}
    }

    function sendData($when)
    {
	$this->data = -1;
	if (isset($this->checks)) {
	    // If we know what the server wants from us push the available data
	    $json = array();
	    foreach ($this->checks as $key => $val) {
		if (true === $val)
		    $val = "true";
		else if (false === $val)
		    $val = "false";
		else if ("" === $val)
		    continue;
		$json[] = array(
		    "host" => ZabbixServer::$hostname,
		    "key" => "yate.$key",
		    "value" => $val,
		    "clock" => $when
		);
		$this->checks[$key] = "";
	    }
	    $json = array(
		"request" => "agent data",
		"data" => $json
	    );
	}
	else
	    // request the list of items from the server
	    $json = array(
		"request" => "active checks",
		"host" => ZabbixServer::$hostname
	    );
	$json = json_encode($json);
	$len = strlen($json);
	$buf = "ZBXD\x01";
	for ($i = 0; $i < 8; $i++) {
	    $buf .= chr($len & 0xff);
	    $len = $len >> 8;
	}
	$buf .= $json;
	$len = strlen($buf);
	if (fwrite($this->socket,$buf,$len) !== $len) {
	    Yate::Output("Failed to send $len octets to " . $this->info);
	    return false;
	}
	$this->header = "";
	return true;
    }

    function readData($when)
    {
	if (!isset($this->length)) {
	    $r = fread($this->socket,13 - strlen($this->header));
	    if ((false === $r) || ("" == $r)) {
		Yate::Output("Error reading header from " . $this->info);
		return false;
	    }
	    $this->header .= $r;
	    if (strlen($this->header) < 13)
		return true;
	    if (substr($this->header,0,5) != "ZBXD\x01") {
		Yate::Output("Invalid protocol from " . $this->info);
		return false;
	    }
	    $len = 0;
	    for ($i = 0; $i < 8; $i++)
		$len |= (ord(substr($this->header,$i + 5,1)) << (8 * $i));
	    if (($len < 1) || ($len > 65536)) {
		Yate::Output("Invalid payload length $len from " . $this->info);
		return false;
	    }
	    Yate::Debug("Will read $len octets payload from " . $this->info);
	    $this->length = $len;
	    $this->buffer = "";
	}

	$r = fread($this->socket,$this->length - strlen($this->buffer));
	if ((false === $r) || ("" == $r)) {
	    Yate::Output("Error reading payload from " . $this->info);
	    return false;
	}
	$this->buffer .= $r;
	if (strlen($this->buffer) < $this->length)
	    return true;
	$this->disconnect();
	$this->timeout = $when + $this->delay;
	$this->processData($this->buffer,$when);
	return true;
    }

    function fetchData()
    {
	if (!isset($this->checks))
	    return true;
	if ($this->data < 0) {
	    $this->data = 0;
	    $m = new Yate("api.request");
	    $m->SetParam("module","zabbix");
	    $m->SetParam("type","control");
	    $m->SetParam("operation","query_stats");
	    $m->SetParam("details",true);
	    $m->SetParam("server",$this->host);
	    $m->Dispatch();
	}
	return ($this->data > 0);
    }

    function processFetched($json,$prefix = "")
    {
	foreach ($json as $key => $val) {
	    $key = str_replace('.[','[',$prefix . $key);
	    if (is_array($val))
		$this->processFetched($val,"$key.");
	    else if (isset($this->checks[$key]))
		$this->checks[$key] = $val;
	}
	if ("" == $prefix)
	    $this->data = 1;
    }

    function processData($data,$when)
    {
	Yate::Debug("Received from " . $this->info . " $data");
	$json = json_decode($data,true);
	if ($json && isset($json["response"])) {
	    if ("success" != $json["response"]) {
		Yate::Output("Received " . $json["response"] . " response from " . $this->info);
		return;
	    }
	    if (isset($this->checks)) {
		if (isset($json["info"])) {
		    $info = $json["info"];
		    if (preg_match('/failed: *[1-9]/',$info))
			Yate::Output($this->info . " $info");
		    else
			Yate::Debug($this->info . " $info");
		}
		else
		    Yate::Debug($this->info . " returned success");
	    }
	    else if (isset($json["data"]) && is_array($json["data"])) {
		$delay = 600;
		$count = 0;
		foreach ($json["data"] as $data) {
		    if (!isset($data["key"]))
			continue;
		    $key = $data["key"];
		    if (substr($key,0,5) != "yate.")
			continue;
		    $count++;
		    if (!isset($this->checks))
			$this->checks = array();
		    $this->checks[substr($key,5)] = "";
		    if (isset($data["delay"])) {
			$dly = $data["delay"];
			if (is_int($dly)) {
			    if ($dly < 15)
				$dly = 15;
			    if ($delay > $dly)
				$delay = $dly;
			}
		    }
		}
		if ($count) {
		    Yate::Output($this->info . " wants $count parameters every $delay seconds: " . implode(", ",array_keys($this->checks)));
		    $this->delay = $delay;
		    unset($this->timeout);
		}
		else
		    Yate::Debug($this->info . " did not request any Yate items, will retry in " . $this->delay . " seconds");
	    }
	    return;
	}
	Yate::Output("Received invalid JSON from " . $this->info . " $data");
    }

    function timerTick($when)
    {
	if (isset($this->socket)) {
	    if (!$this->readData($when)) {
		$this->disconnect();
		$this->timeout = $when + $this->delay;
	    }
	}
	else if (isset($this->timeout) && ($when < $this->timeout))
	    return;
	else if ($this->fetchData()) {
	    if ($this->connect() && $this->sendData($when))
		unset($this->timeout);
	    else {
		$this->disconnect();
		$this->timeout = $when + $this->delay;
	    }
	}
    }

    static function add($server)
    {
	if (preg_match('/^[[:space:]]*([^[:space:]]*)[[:space:]]*$/',$server,$m))
	    $server = $m[1];
	if (preg_match('/^([[:alnum:]_.-]+):([1-9][0-9]*)$/',$server,$m))
	    ZabbixServer::$list[] = new ZabbixServer($m[1],1 * $m[2]);
	else if (preg_match('/^\[([[:xdigit:]:]+)\]:([1-9][0-9]*)$/',$server,$m))
	    ZabbixServer::$list[] = new ZabbixServer($m[1],1 * $m[2]);
	else if (preg_match('/^([[:xdigit:]:]+)$/',$server,$m))
	    ZabbixServer::$list[] = new ZabbixServer($m[1],10051);
	else if (preg_match('/^([[:alnum:]_.-]+)$/',$server,$m))
	    ZabbixServer::$list[] = new ZabbixServer($m[1],10051);
	else
	    Yate::Output("Invalid Zabbix host: $server");
    }

    static function fetched($host,$json)
    {
	if ("" == $host)
	    return;
	$json = json_decode($json,true);
	if (!$json)
	    return;
	foreach (ZabbixServer::$list as $s) {
	    if ($host == $s->host) {
		$s->processFetched($json);
		break;
	    }
	}
    }

    static function resetAll($force)
    {
	foreach (ZabbixServer::$list as $s)
	    $s->reset($force);
    }

    static function runTimers()
    {
	$now = time();
	foreach (ZabbixServer::$list as $s)
	    $s->timerTick($now);
    }

} // class ZabbixServer

$args = Yate::Arg();
if (null === $args || "" == $args) {
    Yate::Output("Missing Zabbix servers list!");
    exit;
}
$args = explode(",",$args);
foreach ($args as $serv)
    ZabbixServer::add($serv);
if (!count(ZabbixServer::$list)) {
    Yate::Output("No Zabbix server could be added!");
    exit;
}

Yate::SetLocal("trackparam","zabbix");
Yate::SetLocal("restart",true);
// Request engine configuration, will start after receiving it
Yate::GetLocal("engine.nodename");
Yate::GetLocal("engine.configname");

// Suppressing all errors is brutal but @ just doesn't do the job
set_error_handler(function(){return true;});

for (;;) {
    $ev = Yate::GetEvent();
    if (false === $ev)
        break;
    if (true === $ev)
        continue;
    switch ($ev->type) {
	case "answer":
	    switch ($ev->name) {
		case "engine.timer":
		    ZabbixServer::runTimers();
		    break;
		case "engine.init":
		    switch ($ev->GetValue("plugin")) {
			case null:
			case "":
			    ZabbixServer::resetAll(false);
			    break;
			case "zabbix":
			    ZabbixServer::resetAll(true);
		    }
		    break;
		case "api.request":
		    ZabbixServer::fetched($ev->GetValue("server"),$ev->GetValue("json"));
		    break;
	    }
	    break;
	case "setlocal":
	    switch ($ev->name) {
		case "engine.nodename":
		    $node = $ev->retval;
		    break;
		case "engine.configname":
		    ZabbixServer::$hostname = $ev->retval . " - $node";
		    // Got all configuration, now start processing the timer
		    Yate::Watch("engine.timer");
		    Yate::Watch("engine.init");
		    break;
	    }
	    break;
    }
}

Yate::Output("Zabbix: bye!");

/* vi: set ts=8 sw=4 sts=4 noet: */
?>