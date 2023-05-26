/**
 * lib_srv_qualifier.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Server state monitoring library
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2019-2023 Null Team
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

#require "lib_str_util.js"

srv_config = new ConfigFile(Engine.configFile(conf_name));

Engine.debugName(module_name);
Message.trackName(module_name);

function Server()
{
    this.available = "unknown";
    this.downCounter = 0;
    this.stateTs = Date.now() / 1000;
}

Server.list = { };
Server.block_list = { };
Server.timer_list = [ ];
//Server.type = "";
Server.check_idx = 0;

Server.prototype = new Object;

Server.build = function(name,params)
{
    if (!(name && params))
	return undefined;
    var srv = undefined;
    switch (Server.type) {
	case "SIP":
	    srv = new SIPServer(name);
	    break;
	case "HTTP":
	    srv = new HTTPServer(name);
	    break;
	default:
	    return undefined;
    }
    if (srv.init(params))
	return srv;
    return undefined;
};

Server.prototype.init = function(params)
{
    Engine.debug(Engine.DebugStub,"Missing init implementation for server type '" + Server.type + "'"),
    return true;
};

Server.prototype.cleanup = function()
{
    delete Server.list[this.name];
    delete Server.block_list[this.name];
    var idx = Server.timer_list.indexOf(this);
    delete Server.timer_list[idx];
};


function SIPServer(name)
{
    this.name = name;
    this.trans_count = sip_trans_count;
    this.uri = "";
    this.available = "unknown";
    this.downCounter = 0;
    this.stateTs = Date.now() / 1000;
}

SIPServer.prototype = new Server;

SIPServer.prototype.init = function(params)
{
    if (!params)
	return false;
    this.uri = params.getValue("uri",name);
    if (!this.uri) {
	Engine.debug(Engine.DebugConf,"Invalid URI for server '" + this.name + "'");
	return false;
    }

    this.conn_id = params.getValue("conn_id");
    if (!this.conn_id) {
	this.line = params.getValue("line");
	if (!this.line) {
	    Engine.debug(Engine.DebugConf,"Missing both connection ID configuration and line configuration for server '" + this.name + "'");
	    return false;
	}
    }
    this.trans_count = params.getIntValue("trans_count",sip_trans_count);
    return true;
};

SIPServer.prototype.check = function()
{
    var m = new Message("xsip.generate");
    m.method = "OPTIONS";
    m.wait = true;
    if (this.conn_id)
	m.connection_id = this.conn_id;
    else
	m.line = this.line;
    m.xsip_trans_count = this.trans_count;
    m.uri = "sip:" + this.uri;
    m.dispatch(true);
    var ok = (m.code / 100) == 2;
    if (ok == this.available)
        return;
    this.available = ok;
    this.stateTs = Date.now() / 1000;
    if (ok)
        Engine.debug(Engine.DebugNote,"Server",this.name,"is available");
    else {
        Engine.debug(Engine.DebugMild,"Server",this.name,"is unavailable");
	this.downCounter++;
    }
    m = new Message("xsip.qualify");
    m.server = this.uri;
    m.name = this.name;
    m.available = ok;
    m.enqueue();
};


function HTTPServer(name)
{
    this.name = name;
    this.timeout = http_timeout;
    this.autoblock = http_autoblock;
    this.url = "";
    this.available = "unknown";
    this.downCounter = 0;
    this.stateTs = Date.now() / 1000;
}

HTTPServer.handlerInstalled = false;

HTTPServer.prototype = new Server;

HTTPServer.prototype.init = function(params)
{
    if (!params)
	return false;
    this.url = params.getValue("url",name);
    if (!this.url) {
	Engine.debug(Engine.DebugConf,"Missing URL for server '" + this.name + "'");
	return false;
    }
    this.compareUrl = this.url;
    if (!this.compareUrl.endsWith("/"))
	this.compareUrl += "/";
    this.timeout = params.getIntValue("timeout",http_timeout) * 1000;
    this.autoblock = params.getBoolValue("autoblock",http_autoblock);
    return true;
};

HTTPServer.prototype.check = function()
{
    var m = new Message("http.request");
    m.module = module_name;
    m.url = this.url;
    m.wait = true;
    m.timeout = this.timeout;

    var ok = m.dispatch(true);
    if (ok) { // request was handled
	// error means that something failed
	if (!!m.error)
	    ok = false;
	else {
	    // for the C++ client the presence of code means that a HTTP response was received.
	    // for shell script, the fact that we have no error set means success
	    if (!isNaN(1 * m.code))
		ok = (m.code / 100) == 2;
	}
    }

    if (ok == this.available)
        return;
    this.available = ok;
    this.stateTs = Date.now() / 1000;
    if (ok) {
        Engine.debug(Engine.DebugNote,"Server",this.name,"is available");
	Server.block_list[this.name] = undefined;
    }
    else {
        Engine.debug(Engine.DebugMild,"Server",this.name,"is unavailable");
	this.downCounter++;
	if (this.autoblock && Server.list[this.name] == this) {
	    Server.block_list[this.name] = this;
	    if (!HTTPServer.handlerInstalled) {
		Message.install(onHTTPBlock,"http.request",http_req_prio);
		HTTPServer.handlerInstalled = true;
	    }
	}
    }
    var msg = new Message("http.qualify");
    msg.server = this.url;
    msg.name = this.name;
    msg.autoblock = this.autoblock;
    msg.available = ok;
    msg.error = m.error;
    msg.code = m.code;
    msg.enqueue();
};

function onHTTPBlock(msg)
{
    if (msg.module == module_name)
	return false;
    var url = msg.url;
    if (!url.endsWith("/"))
	url += "/";
    for (var s of Server.block_list) {
	if (url.startsWith(s.compareUrl)) {
	    msg.error = "failure";
	    if (!msg.wait && msg.notify) {
		var m = new Message("http.notify");
		m.module = module_name;
		m.targetid = msg.notify;
		m.error = "failure";
		m.enqueue();
	    }
	    return true;
	}
    }
    return false;
}

// common general settings
check_interval = 10; // seconds



function onInterval()
{
    if (!Server.timer_list.length)
	return;
    var srv = Server.timer_list[Server.check_idx++];
    if (srv) {
	Engine.debug(Engine.DebugInfo,"Checking server #" + (Server.check_idx  - 1),srv.name);
	if (srv.check)
	    srv.check();
	else
	    Engine.debug(Engine.DebugWarn,"Missing implementation for checking state of server '" + srv.name + "'");
    }
    // re-check the list, it may have been modified during check
    if (Server.timer_list.length)
	Server.check_idx %= Server.timer_list.length;
    else
	Server.check_idx = 0;
}

function configServers(config)
{
    if (!config)
	return;
    var sections = config.sections();
    var servers = [];
    for (var sect of sections) {
	var n = "" + sect;
	if (!n.startsWith("server "))
	    continue;
	var name = n.substr(7);
	if (!name) {
	    Engine.debug(Engine.DebugConf,"Invalid section name '" + n + "'");
	    continue;
	}
	var enable = sect.getBoolValue("enable",true);
	if (enable) {
	    var srv = Server.list[name];
	    if (srv) {
		if (!srv.init(sect)) {
		    srv.cleanup();
		    continue;
		}
	    }
	    else {
		srv = Server.build(name,sect);
		if (!srv)
		    continue;
		Server.list[name] = srv;
	    }
	    servers.push(srv);
	}
	else {
	    var srv = Server.list[name];
	    if (srv)
		srv.cleanup();
	}
    }
    // remove servers that were no longer in configuration file
    for (var s of Server.list) {
	if (!servers.includes(s)) {
	    Engine.debug(Engine.DebugAll,"Removing server '" + s.name + "', no longer configured");
	    s.cleanup();
	}
    }
    if (Server.check_idx >= servers.length)
	Server.check_idx = 0;
    Server.timer_list = servers;
}


function clearTimer(dbg)
{
    if (isNaN(Server.timer_id))
	return;
    if (dbg)
	Engine.debug(Engine.DebugAll,"Clearing interval of " + check_interval + " seconds: " + dbg);
    Engine.clearInterval(Server.timer_id);
    Server.timer_id = undefined;
}

// Load or reload configuration from file
function loadCfg(first)
{
    Engine.output("Initializing module " + Server.type + "Server Qualifier");
    srv_config.load(true);

    switch (Server.type) {
	case "SIP":
	    sip_trans_count = srv_config.getIntValue("general","trans_count",sip_trans_count);
	    break;
	case "HTTP":
	    http_autoblock = srv_config.getBoolValue("general","autoblock",http_autoblock);
	    http_timeout = srv_config.getIntValue("general","timeout",http_timeout);
	    http_req_prio = srv_config.getIntValue("general","http_req_prio",http_req_prio);
	    break;
	default:
	    Engine.debug(Engine.DebugConf,"Unknown server type '" + Server.type + "'");
	    return false;
    }
    configServers(srv_config);

    if (!Server.timer_list.length) {
	clearTimer("no configured servers");
	return true;
    }
    var tmp = srv_config.getIntValue("general","check_interval",check_interval);
    if (tmp != check_interval) {
	clearTimer("reconfigured");
	check_interval = tmp;
    }
    if (isNaN(Server.timer_id)) {
	Server.timer_id = Engine.setInterval(onInterval,check_interval * 1000);
	if (debug)
	    Engine.debug(Engine.DebugAll,"Set interval to " + check_interval + " seconds");
    }
    return true;
}

// Handle the reload command
function onReload(msg)
{
    if (msg.plugin && (module_name != msg.plugin))
	return false;
    loadCfg();
    return !!msg.plugin;
}

// Handle node shutdown
function onHalt()
{
    if (Server.list) {
	for (var s of Server.list)
	    s.cleanup();
    }
    Server.list = { };
    Server.block_list = { };
    Server.timer_list = [ ];
    clearTimer();
}

// Handle debugging commands
function onDebug(msg)
{
    Engine.setDebug(msg.line);
    debug = Engine.debugEnabled();
    msg.retValue(Server.type + " server Qualifier debug " + Engine.debugEnabled() + " level " + Engine.debugLevel() + "\r\n");
    return true;
}

// Handle the status command
function onStatus(msg)
{
    if (msg.module && (module_name != msg.module))
	return false;
    var str = "name=" + module_name + ",type=misc,format=" + Server.status_format + ";servers=" + Server.timer_list.length;
    var avail = 0;
    var unavail = 0;
    for (var s of Server.timer_list) {
	if (s) {
	    if (true === s.available)
		avail++;
	    else if (false === s.available)
		unavail++;
	}
    }
    str += ",avail=" + avail + ",unavail=" + unavail;
    if ("HTTP" == Server.type) {
	var blocked = 0;
	for (var s of Server.block_list) {
	    if (s)
		blocked++;
	}
	str += ",blocked=" + blocked;
    }
    if (parseBool(msg.details,true)) {
	var first = true;
	var crtTs = Date.now() / 1000;
	for (var s of Server.timer_list) {
	    if (!s)
		continue;
	    var tmp;
	    switch (Server.type) {
		case "SIP":
		    tmp =  s.name + "=" + s.uri + "|" + s.available + "|" + s.downCounter + "|" + (crtTs - s.stateTs);
		    break;
		case "HTTP":
		    tmp =  s.name + "=" + s.url + "|" + s.available + "|" + s.autoblock + "|" + s.downCounter + "|" + (crtTs - s.stateTs);
		    break;
		default:
		    continue;
	    }
	    if (first) {
		str += ";" + tmp;
		first = false;
	    }
	    else
		str += "," + tmp
	}
    }
    msg.retValue(msg.retValue() + str + "\r\n");
    return !!msg.module;
}

function onCommand(msg)
{
    switch (msg.partline) {
	case "help":
	case "status":
	case "reload":
	case "debug":
	    if (!msg.partword || module_name.startsWith(msg.partword))
		oneCompletion(msg,module_name,part);
	    break;
    }
    return false;
}

function qualifier_init(first)
{
    if (first) {
	// SIP server general settings
	if (Server.type == "SIP") {
	    sip_trans_count = 3;
	    Server.status_format="URI|Up|DownCounter|CrtStateSec";
	}
	// HTTP server general settings
	else if (Server.type = "HTTP") {
	    http_autoblock = true;
	    http_timeout = 5;
	    // HTTP client C++ is at 90, shell script at 95
	    http_req_prio = 85;
	    Server.status_format="URL|Up|Block|DownCounter|CrtStateSec";
	}

	Message.install(onCommand,"engine.command",120);
	Message.install(onStatus,"engine.status",120);
	Message.install(onReload,"engine.init",120);
	Message.install(onHalt,"engine.halt",50);
	Message.install(onDebug,"engine.debug",150,"module",module_name);
    }
    loadCfg(first);
}



function onUnload()
{
    Message.uninstall();
    onHalt();
}

/* vi: set ts=8 sw=4 sts=4 noet: */
