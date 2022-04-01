/**
 * prettify.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Prettify command(s) result and other data
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2018 Null Team
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

Engine.debugName("prettify");
Message.trackName("prettify");

warnedStatusSectionsMore = {};
warnedStatusItemCols = {};
print_empty_content = true;
print_trailing_spaces = false;
native_dump = false;

db_cmd = true;
db_null = "<NULL>";
db_undefined = "";

help = "";
helpNice = "";

// Name of the first column in status command result
// Name = regexp to match module
statusFirstColName = {};
// Subsequent column(s) in status command result. Used when format is missing
// module -> array of name(s)
statusFmtMissingCols = {};

debug = true;

function initialize(first)
{
    Engine.output("Initializing module Prettify");
    var cfg = new ConfigFile(Engine.configFile("prettify"));
    var gen = cfg.getSection("general",true);

    if (first) {
	var tmp = gen.getValue("debug");
	if (tmp != "")
	    Engine.setDebug(tmp);
	debug = Engine.debugEnabled();
    }

    help = helpNice = "";
    print_empty_content = gen.getBoolValue("print_empty_content",true);
    print_trailing_spaces = gen.getBoolValue("print_trailing_spaces");
    native_dump = gen.getBoolValue("native_dump");
    db_cmd = gen.getBoolValue("db_cmd",true);
    db_null = gen.getValue("db_null","<NULL>");
    db_undefined = gen.getValue("db_undefined","");

    statusFirstColName = {
	Chan: /^(sip|sig|iax|jingle|h323|cdrbuild)$/,
	Username: /^(regfile)$/,
	Node: /^diameter allnodes$/,
	Peer: /^diameter (allpeers|node .*)$/,	
    };
    if (var keys = cfg.keys("status_first_column_name")) {
	for (var k of keys) {
	    var tmp = sect.getValue(k);
	    if (tmp) {
		tmp = new RegExp(tmp);
		if (!tmp.valid()) {
		    Engine.debug(Engine.DebugConf,"Invalid regexp '" + k +
			"'='" + sect.getValue(k) + "' in section '" + sect + "'");
		    tmp = null;
		}
	    }
	    if (tmp)
		statusFirstColName[k] = tmp;
	    else
		delete statusFirstColName[k];
	}
    }

    statusFmtMissingCols = {
	accfile: ["Username"],
	regfile: ["Location"],
    };
    if (var keys = cfg.keys("status_missing_fmt_columns")) {
	for (var k of keys) {
	    var tmp = sect.getValue(k);
	    if (tmp) {
		tmp = tmp.split("|");
		if (!tmp.length())
		    tmp = null;
	    }
	    if (tmp)
		statusFmtMissingCols[k] = tmp;
	    else
		delete statusFmtMissingCols[k];
	}
    }

    if (debug && gen.getBoolValue("dump_init")) {
	var config = {
	    print_empty_content: print_empty_content,
	    print_trailing_spaces: print_trailing_spaces,
	    native_dump: native_dump,
	    db_cmd: db_cmd,
	    db_null: db_null,
	    db_undefined: db_undefined,
	    status_first_column_name: statusFirstColName,
	    status_missing_fmt_columns: statusFmtMissingCols,
	};
	Engine.debug(Engine.DebugAll,"Initialized\r\n-----\r\n" + Engine.dump_var_r(config) + "\r\n-----");
    }
}

function prettyDump(lengths,titles,lines,msg,dumpT)
{
    if (!lines.length) {
	if (dumpT || !print_empty_content) {
	    if (msg)
		return false;
	    return "";
	}
    }
    else if (dumpT)
	return Engine.dump_t(lines);
    var hdr = "";
    var sep = "";
    for (var i = 0; i < titles.length; i++) {
	hdr += " " + strFix(titles[i],lengths[i]);
	sep += " " + strFix("-",lengths[i],"-");
    }
    var str = hdr.substr(1) + "\r\n" + sep.substr(1) + "\r\n";
    var trailingSp = print_trailing_spaces;
    for (var i = 0; i < lines.length; i++) {
	var s = "";
	var line = lines[i];
	var spaces = 0;
	for (var j = 0; j < line.length; j++) {
	    if (trailingSp)
		s += " " + strFix(line[j],lengths[j]);
	    else {
		var tmp = line[j];
		if (!tmp.length)
		    spaces += 1 + lengths[j];
		else if (tmp.length < lengths[j]) {
		    s += strFix(" ",spaces + 1) + tmp;
		    spaces = lengths[j] - tmp.length;
		}
		else {
		    s += strFix(" ",spaces + 1) + strFix(tmp,lengths[j]);
		    spaces = 0;
		}
	    }
	}
	if (s)
	    str += s.substr(1) + "\r\n";
    }
    if (msg) {
	msg.retValue(str + "\r\n");
	return true;
    }
    return str;
}

function prettyDumpProps(titles,obj,params)
{
    var lengths = getItemLengths(titles);
    var lines = [];
    var line = [];
    for (var i = 0; i < params.length; i++)
	line.push(obj[params[i]]);
    pushLine(lines,line,lengths);
    return prettyDump(lengths,titles,lines);
}

function splitFields(str)
{
    if (!str)
	return null;
    var res = {};
    str = str.split(",");
    for (var i = 0; i < str.length; i++) {
	var s = str[i];
	var pos = s.indexOf("=");
	if (!pos)
	    continue;
	if (pos > 0)
	    res[s.substr(0,pos)] = s.substr(pos + 1);
	else
	    res[s] = "";
    }
    return res;
}

function getItemLengths(list)
{
    var lengths = [];
    for (var i = 0; i < list.length; i++) {
	var n = list[i];
	n = n.length;
	if (isNaN(n) || !n)
	    lengths.push(1);
	else
	    lengths.push(n);
    }
    return lengths;
}

function pushLine(lines,line,lengths)
{
    if (line.length > lengths.length)
	line = line.splice(0,lengths.length);
    for (var i = 0; i < line.length; i++) {
	var s = line[i];
	if (lengths[i] < s.length)
	    lengths[i] = s.length;
    }
    lines.push(line);
}

// Set first column name in titles array
function setFirstColName(titles,module,msgName)
{
    var name = undefined;
    // Set known first column names
    if ("engine.status" == msgName) {
	for (var i in statusFirstColName) {
	    if (module.match(statusFirstColName[i])) {
		name = [i];
		break;
	    }
	}
    }
    if (!name)
	name = ["Name"];
    return name.concat(titles);
}

function printStatus(msg,retVal,isParse)
{
    if (!retVal)
	return false;
    var ok = !!isParse;
    if (ok) {
	if (!retVal.endsWith("\r\n"))
	    retVal += "\r\n";
	var module = "";
	var fullCmd = "";
    }
    else {
	var module = retVal.trim();
	var m = new Message("engine.status",false,msg);
	m.module = module;
	m.line = undefined;
	m.handlers = undefined;
	ok = m.dispatch(true);
	if (!(retVal = m.retValue()))
	    return ok || msg;
	var fullCmd = module;
	var pos = module.indexOf(" ");
	if (pos > 0)
	    module = module.substr(0,pos);
    }

    var sections = retVal.split(";");
    var statModule = sections[0];
    // Compact last status section(s) when detail section values may contain ';'
    var threeSectsOnly = false;
    if (ok) {
	// Try to detect module from string
	if (!module) {
	    var tmp = splitFields(statModule);
	    if (tmp.module)
		fullCmd = module = tmp.module;
	    if ("diameter" == module) {
		if (tmp.type)
		    fullCmd += " " + tmp.type;
		else if (tmp.name)
		    fullCmd += " " + tmp.name;
	    }
	    else if (!module) {
		if (tmp.name)
		    fullCmd = module = tmp.name;
		else
		    fullCmd = module = "???";
	    }
	}
	switch (fullCmd) {
	    case "diameter allsessions":
		threeSectsOnly = true;
		break;
	}
    }
    else {
	// Some modules return false to status!
	// Try to detect module
	if (statModule.startsWith("name=" + module))
	    ok = true;
	else if (statModule.startsWith("module=" + module))
	    ok = true;
	else {
	    ok = statModule.startsWith("name=accfile,");
	    if (ok)
		fullCmd = module = "accfile";
	}
    }

    var titles = undefined;
    if (ok) {
	titles = statModule.match(/,format=([^;,]+[;,]?)/);
	if (titles) {
	    titles = titles[1];
	    if (titles.endsWith(";") || titles.endsWith(","))
		titles = titles.substr(0,titles.length - 1);
	    else if (titles.endsWith("\r\n"))
		titles = titles.substr(0,titles.length - 2);
	    titles = titles.split("|");
	}
	else
	    titles = statusFmtMissingCols[module];
	if (!titles) {
	    switch (fullCmd) {
		case "diameter":
		case /^diameter ./:
		    return printDiamStatus(fullCmd,msg,retVal,sections);
	    }
	    ok = false;
	}
    }
    if (!ok) {
	if (!msg)
	    return false;
	if (isParse)
	    msg.retValue("Unknown\r\n");
	else
	    msg.retValue(retVal);
	return true;
    }

    titles = setFirstColName(titles,fullCmd,"engine.status");
    var lines = [];
    var dumpT = native_dump;
    if (dumpT) {
	lines.push(titles);
	var lengths = undefined;
    }
    else
	var lengths = getItemLengths(titles);
    while (sections.length > 2) {
	var str = sections[2];
	if (sections.length > 3) {
	    if (threeSectsOnly) {
		sections.shift();
		sections.shift();
		str = sections.join(";");
	    }
	    else if (!warnedStatusSectionsMore[fullCmd]) {
		warnedStatusSectionsMore[fullCmd] = true;
		Engine.debug(Engine.DebugStub,"status",fullCmd,"returned",sections.length,"sections");
	    }
	}
	if (str.endsWith("\r\n"))
	    str = str.substr(0,str.length - 2);
	if (!str.length)
	    break;
	str = str.split(",");
	for (var item of str) {
	    if (!item)
		continue;
	    // Split name=val1|val2...
	    item = "" + item;
	    var pos = item.indexOf("=");
	    var vals = item.substr(0,pos);
	    vals = [vals];
	    item = item.substr(pos + 1);
	    vals = vals.concat(item.split("|"));
	    if (vals.length != titles.length && !warnedStatusItemCols[fullCmd]) {
		warnedStatusItemCols[fullCmd] = true;
		Engine.debug(Engine.DebugStub,"status",fullCmd,"returned item columns",vals.length,
		    "<> format columns",titles.length);
	    }
	    if (dumpT)
		lines.push(vals);
	    else
		pushLine(lines,vals,lengths);
	}
	break;
    }
    var rVal = prettyDump(lengths,titles,lines,msg,dumpT);
    if (!msg)
	return rVal;
    if (!rVal)
	msg.retValue(retVal);
    else if (!msg.retValue())
	msg.retValue(rVal);
    return true;
}

function printDiamStatus(what,msg,retVal,sections)
{
    if (sections.length < 2) {
	msg.retValue(retVal);
	return true;
    }

    var isPeer = false;
    switch (what) {
	case "diameter":
	    break;
    	case "diameter peer":
	case /^diameter peer ./:
	    isPeer = true;
	    break;
	default:
	    msg.retValue(retVal);
	    return true;
    }

    var str = "";
    var params = sections[1];
    if (params.endsWith("\r\n"))
	params = params.substr(0,params.length - 2);
    params = splitFields(params);
    if (isPeer) {
	var sm = splitFields(sections[0]);
	str = "Node: " + params.node;
	if (params.remote_node) {
	    if (sm.name && params.remote_node.endsWith("/" + sm.name))
		str += "\r\nPeer: " + params.remote_node;
	    else {
		str += "\r\nPeer: " + sm.name;
		str += "\r\nRemote node: " + params.remote_node;
	    }
	}
	else
	    str += "\r\nPeer: " + sm.name;
	str += "\r\n\r\n" + prettyDumpProps(
	    ["RoutePrio","Operational","Crt. state","Prev. state","Down alarms"],
	    params,
	    ["route_priority","operational","crt_state_duration","prev_state_duration","down_alarms"]);
	if (params.connection) {
	    // NOTE: other connection related data:  send_queue, requests_queue, codec_queue
	    str += "\r\n" + prettyDumpProps(
		["Connection","Direction","Status","Type","Local addr","Remote addr"],
		params,
		["connection","direction","status","transport","local_addr","remote_addr"]);
	    var lApps = "" + params.local_apps;
	    lApps = lApps.split("-");
	    var rApps = "" + params.remote_apps;
	    rApps = rApps.split("-");
	    var titles = ["Local app","Remote app"];
	    var lengths = getItemLengths(titles);
	    var lines = [];
	    for (var l of lApps) {
		if (!l)
		    continue;
		var remote = "";
		var pos = rApps.indexOf(l);
		if (pos >= 0) {
		    delete rApps[pos];
		    pushLine(lines,[l,"yes"],lengths);
		}
		else
		    pushLine(lines,[l,"-"],lengths);
	    }
	    for (var l of rApps) {
		if (!l)
		    continue;
		pushLine(lines,["-",l],lengths);
	    }
	    str += "\r\n" + prettyDump(lengths,titles,lines);
	}
    }
    else {
	str = prettyDumpProps(["Listeners","Connections","Operational"],
	    params,["listeners","connections","operational"]);
    }
    msg.retValue(str + "\r\n");
    return true;
}

function getDbRes(msg)
{
    var res = "";
    for (var f of getDbRes.fields)
	if (undefined !== (var tmp = msg[f]))
	    res += " " + f + "=" + tmp;
    return res;
}
getDbRes.fields = ["dbtype", "rows", "columns", "affected"];

function printDbQuery(msg,acc,query)
{
    if (!isCmdAdmin(msg)) {
	msg.retValue("Command requires Admin credentials\r\n");
	return true;
    }
    var m = new Message("database");
    m.account = acc;
    m.query = query;
    if (!m.dispatch(true)) {
	msg.retValue("Query not handled\r\n");
	return true;
    }
    if (m.error) {
	msg.retValue("Query failure '" + m.error + "'" + getDbRes(m) + "\r\n");
	return true;
    }

    var rVal = "";
    var rows = m.getRow();
    if (rows.length) {
	if (native_dump) {
	    for (var row of rows) {
		for (var f in row) {
		    if (null === row[f])
			row[f] = db_null;
		}
	    }
	    rVal = Engine.dump_t(rows);
	}
	else {
	    var lengths = undefined;
	    var titles = undefined;
	    var lines = [];
	    for (var row of rows) {
		if (!titles) {
		    titles = [];
		    for (var t in row)
			titles.push(t);
		    if (!titles.length)
			break;
		    lengths = getItemLengths(titles);
		}
		var line = [];
		for (var t of titles) {
		    var tmp = row[t];
		    if (null === tmp)
			line.push(db_null);
		    else if (undefined === tmp)
			line.push(db_undefined);
		    else
			line.push(tmp);
		}
		pushLine(lines,line,lengths);
	    }
	    if (lengths)
		rVal = prettyDump(lengths,titles,lines);
	}
    }
    if (!rVal)
	rVal = "Empty result" + getDbRes(m);
    msg.retValue(rVal + "\r\n");
    return true;

}

function isCmdAdmin(msg)
{
    return parseBool("" + msg.cmd_admin);
}

function onReload(msg)
{
    if (msg.plugin && Engine.debugName() != msg.plugin)
	return false;
    initialize();
    return !!msg.plugin;
}

function onCommand(msg)
{
    // Avoid our messages
    if (msg.prettify)
	return false;
    var line = "" + msg.line;
    if (line) {
	if (var m = line.match(/^prettify ([^ ]+) (.+)$/)) {
	    switch (m[1]) {
		case "status":
		    return printStatus(msg,m[2]);
		case "db":
		    if (db_cmd) {
			m = m[2];
			if (m = m.match(/^([^ ]+) (.+)$/))
			    return printDbQuery(msg,m[1],m[2]);
		    }
		    return false;
		case "xml":
		    if (var xml = new XML(m[2]))
			msg.retValue(xml.xmlText(2) + "\r\n");
		    else
			msg.retValue("Invalid XML\r\n");
		    return true;
		case "json":
		    if (var json = JSON.parse(m[2]))
			msg.retValue(JSON.stringify(json,undefined,2) + "\r\n");
		    else
			msg.retValue("Invalid JSON\r\n");
		    return true;
		case "result-status":
		    return printStatus(msg,m[2],true);
	    }
	    return false;
	}
	return false;
    }
    switch (msg.partline) {
	case null:
	case undefined:
	case "":
	case "help":
	case "reload":
	    oneCompletion(msg,"prettify",msg.partword);
	    return false;
	case "prettify":
	    oneCompletion(msg,"status",msg.partword);
	    if (db_cmd)
		oneCompletion(msg,"db",msg.partword);
	    oneCompletion(msg,"xml",msg.partword);
	    oneCompletion(msg,"json",msg.partword);
	    oneCompletion(msg,"result-status",msg.partword);
	    return false;
	case /^prettify status( .*)?$/:
	    var m = new Message("engine.command",false,msg);
	    m.prettify = true;
	    if (msg.partial)
		m.partial = msg.partial.substr(16);
	    if (msg.partline)
		m.partline = "status" + msg.partline.substr(15);
	    var ok = m.dispatch(true);
	    msg.retValue(m.retValue());
	    return ok;
    }
    return false;
}

function onHelp(msg)
{
    if (!helpNice) {
	helpNice = "  prettify status <module>\r\nSend status and prettify the result\r\n";
	if (db_cmd)
	    helpNice += "  prettify db <account> <query>\r\nPrettify database query result\r\n";
	helpNice +=
	    "  prettify {xml|json} <str>\r\nPrettify requested data\r\n"
	  + "  prettify result-status <str>\r\nPrettify an existing status result\r\n";
    }
    if (!help) {
	var cfg = "status";
	if (db_cmd)
	    cfg += "|db";
	cfg += "|xml|json|result-status";
	help = "  prettify {" + cfg + "} <param(s)>\r\n";
    }

    if (msg.line) {
	if ("prettify" == msg.line) {
	    msg.retValue(helpNice);
	    return true;
	}
	return false;
    }
    msg.retValue(msg.retValue() + help);
    return false;
}

Message.install(onCommand,"engine.command",120);
Message.install(onHelp,"engine.help",199);
Message.install(onReload,"engine.init",200);
initialize(true);

/* vi: set ts=8 sw=4 sts=4 noet: */
