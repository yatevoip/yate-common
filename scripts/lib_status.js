/**
 * lib_status.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Status retrieval and parsing utility functions library for Javascript
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

#require "lib_str_util.js"

// Retrieve engine and module(s) statistics
function retrieveStats(prefix,module,details)
{
    var found = false;
    var res = { };
    var msg = new Message("engine.status");
    if (isFilled(module))
	msg.module = module;
    msg.details = !!details;
    msg.dispatch(true);
    msg = msg.retValue();
    msg = msg.split('\n');

    for (var i = 0; i < msg.length; i++) {
	var line = msg[i];
	if (line.endsWith('\r'))
	    line = line.substr(0,line.length - 1);
	if ("" == line)
	    continue;
	line = line.split(';');
	var param = line[0];
	if (isFilled(module)) {
	    var name = module;
	    var sep;
	    while ((sep = name.indexOf(' ')) >= 0)
		name = name.substr(0,sep) + '_' + name.substr(sep + 1);
	}
	else if (param.startsWith("name=")) {
	    param = param.substr(5);
	    param = param.split(',');
	    var name = param[0];
	}
	else
	    continue;
	if ("engine" == name) {
	    res.engine = { };
	    for (var j = 1; j < param.length; j++) {
		var p = param[j];
		if (p.startsWith("type="))
		    continue;
		var sep = p.indexOf('=');
		if (sep <= 0)
		    continue;
		var n = p.substr(0,sep);
		p = p.substr(sep + 1);
		switch (p) {
		    case "true":
			p = true;
			break;
		    case "false":
			p = false;
			break;
		    case /^[0-9]+$/:
			p = 1 * p;
			break;
		}
		res.engine[n] = p;
	    }
	    if ("" == res.engine["runid"]) {
		var n = 1 * Engine.runParams("runid");
		if (!isNaN(n))
		    res.engine["runid"] = n;
	    }
	    if (Engine.uptime) {
		res.uptime = { };
		res.uptime.wall = Engine.uptime(Engine.WallTime);
		res.uptime.user = Engine.uptime(Engine.UserTime);
		res.uptime.kernel = Engine.uptime(Engine.KernelTime);
	    }
	}
	else if (Array.isArray(prefix)) {
	    var ok = false;
	    for (var j = 0; j < prefix.length; j++) {
		if (name.startsWith(prefix[j])) {
		    ok = true;
		    break;
		}
	    }
	    if (!ok)
		continue;
	}
	else if (isFilled(prefix) && !name.startsWith(prefix))
	    continue;

	param = "" + line[1];
	param = param.split(',');
	for (var j = 0; j < param.length; j++) {
	    var p = param[j];
	    var sep = p.indexOf('=');
	    if (sep <= 0)
		continue;
	    var n = p.substr(0,sep);
	    p = p.substr(sep + 1);
	    switch (p) {
		case "true":
		    p = true;
		    break;
		case "false":
		    p = false;
		    break;
		case /^[0-9]+$/:
		    p = 1 * p;
		    break;
	    }
	    if (!res[name])
		res[name] = { };
	    res[name][n] = p;
	    found = true;
	}

	if ((line.length < 3) || !details)
	    continue;
	param = "" + line[2];
	param = param.split(',');
	for (var j = 0; j < param.length; j++) {
	    var p = param[j];
	    var sep = p.indexOf('=');
	    if (sep <= 0)
		continue;
	    var n = "[" + p.substr(0,sep) + "]";
	    p = p.substr(sep + 1);
	    switch (p) {
		case "true":
		    p = true;
		    break;
		case "false":
		    p = false;
		    break;
		case /^[0-9]+$/:
		    p = 1 * p;
		    break;
	    }
	    if (!res[name])
		res[name] = { };
	    res[name][n] = p;
	    found = true;
	}
    }

    if (found)
	return res;
    return null;
}

// Merge extra submodule statistics
function mergeStats(stats,module,details)
{
    if (!stats)
	return;
    if (Array.isArray(module)) {
	for (var tmp of module)
	    mergeStats(stats,tmp,details);
    }
    else {
	var tmp = retrieveStats(undefined,module,details);
	if (tmp) {
	    for (var i in tmp)
		stats[i] = tmp[i];
	}
    }
}

/* vi: set ts=8 sw=4 sts=4 noet: */
