/**
 * lib_cfg_util.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Configuration utility functions library for Javascript
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2019 Null Team
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

// Check a JSON content and extracted parameters
// Returns true on success, sets a 40x error and returns false on failure
function checkJson(error,params,json)
{
    if (params)
	return true;
    if (json) {
	error.reason = "Invalid JSON content.";
	error.error = 401;
    }
    else {
	error.reason = "Missing all parameters.";
	error.error = 402;
    }
    return false;
}

// Set a 401 Invalid Parameter error
// Returns retVal or null (if retVal is undefined)
function setInvalidParam(error,param,value,reason,retVal)
{
    error.reason = "";
    if (isPresent(param)) {
	error.reason = "Invalid '" + param + "' value";
	if (isPresent(value))
	    error.reason += " '" + value + "'";
    }
    if (reason) {
	if (error.reason)
	    error.reason += " - ";
	error.reason += reason;
    }
    error.reason += ".";
    error.error = 401;
    if (undefined === retVal)
	return null;
    return retVal;
}

// Set a 402 Missing Parameter error
// Returns retVal or null (if retVal is undefined)
function setMissingParam(error,param,retVal)
{
    error.reason = "Missing '" + param + "' parameter.";
    error.error = 402;
    if (undefined === retVal)
	return null;
    return retVal;
}

// Set a 501 error with the specified reason
// Returns retVal or null (if retVal is undefined)
function setStorageError(error,reason,retVal)
{
    error.reason = reason + ".";
    error.error = 501;
    if (undefined === retVal)
	return null;
    return retVal;
}

// Prepare a config file:
// Load, clear, set updated info
function prepareConf(name,msg,clear,custom)
{
    if (!name)
        return false;	
    var c = new ConfigFile(Engine.configFile(name));
    var l = c.getBoolValue("general","locked");
    if (false !== clear)
	c.clearSection();
    if (isFilled(custom))
	c.getSection("$include " + custom + ".conf",true);
    c.setValue("general","updated",msg.received);
    c.setValue("general","locked",l);
    return c;
}

// Save config file, return proper error if locked or filesystem error
function saveConf(error,conf)
{
    if (conf.getBoolValue("general","locked"))
	return setStorageError(error,"Locked config file '" + conf.name() + "'",false);
    if (conf.save())
	return true;
    return setStorageError(error,"Failed to save config file '" + conf.name() + "'",false);
}

// Check if one or more config files are locked
// Sets an error and returns false if any config file is locked
function checkUnlocked(error,confs)
{
    for (var c of confs) {
	if (c.getBoolValue("general","locked"))
	    return setStorageError(error,"One or more of the config files is locked",false);
    }
    return true;
}

// Build a list of locked configurations
function listLocked(sect)
{
    var list = [ ];
    for (var item in sect) {
	for (var name of sect[item]) {
	    var c = new ConfigFile(Engine.configFile(name));
	    if (c.getBoolValue("general","locked")) {
		list.push(item);
		break;
	    }
	}
    }
    return list;
}

// Configure regexroute
function RegexConfig()
{
    this.error = new Object;
    this.rexUser = null;
}
RegexConfig.prototype = new Object;

RegexConfig.prototype.prepareConfig = function(msg)
{
    this.rexUser = prepareConf("rex-user-config",msg);
};

RegexConfig.prototype.saveConfig = function()
{
    if (!checkUnlocked(this.error,[this.rexUser]))
	return false;
    return saveConf(this.error,this.rexUser);
};

RegexConfig.prototype.buildConfig = function(params)
{
    if (!params.rules)
	return true;
    if (!Array.isArray(params.rules))
	return setInvalidParam(this.error,"rules",null,"Must be an array",false);
    if (!params.rules.length)
	return setInvalidParam(this.error,"rules",null,"Empty array",false);
    for (var i = 0; i < params.rules.length; i++) {
	var r = params.rules[i];
	if ("object" != typeof r)
	    return setInvalidParam(this.error,"rule[" + i + "]",null,"Must be an object",false);
	if (isEmpty(r.rules))
	    return setMissingParam(this.error,"rule[" + i + "].rules",false);
	var name = false;
	if (isFilled(r.name)) {
	    name = r.name;
	    if ("string" != typeof name)
		return setInvalidParam(this.error,"rule[" + i +"].name",null,"Must be string",false);
	}
	else
	    name = "" + i;
	var routeString = r.rules;
	if ("string" != typeof routeString)
	    return setInvalidParam(this.error,"rule[" + i + "]",null,"Must be string",false);
	routeString = routeString.trim();
	if ("[" != routeString.charAt(0))
	    return setInvalidParam(this.error,"rule[" + i + "].rules",null,"Must begin with a configuration [section]",false);
	var lines = routeString.split("\n");
	var sect = null;
	var cont = "";
	for (var l of lines) {
	    l = cont + l.trim();
	    cont = "";
	    if (!l)
		continue;
	    // handle section name
	    if ("[" == l.charAt(0)) {
		var pos = l.indexOf("]");
		if (0 >= pos)
		    return setInvalidParam(this.error,"rule[" + i +  "].rules",l,"Missing ']' for section name",false);
		pos = l.substr(1,pos - 1);
		pos = pos.trim();
		if (!pos.length)
		    return setInvalidParam(this.error,"rule[" + i + "].rules",l,"Empty section name",false);
		sect = this.rexUser.getSection(pos,true);
		if (!sect) {
		    this.error.reason += "Could not get section '" + pos +"'.";
		    this.error.error = 401;
		    return false;
		}
		sect.addValue(";begin rules for template",name);
	    }
	    else {
		// handle line
		var pos = l.indexOf("=");
		if (0 >= pos) {
		    switch (l.substr(0,1)) {
			case /^}$/:
			    pos = l.length;
			    break;
			case /^;/:
			    pos = 1;
			    l = ";=" + l.substr(1);
			    break;
			default:
			    return setInvalidParam(this.error,"rule[" + i + "].rules",l,"Missing '=' in routing rule",false);
		    }
		}
		if (l.endsWith("\\")) {
		    cont = l.substr(0,l.length - 1);
		    continue;
		}
		var crit = l.substr(0,pos);
		var action = l.substr(pos + 1);
		crit = crit.trim();
		action = action.trim();
		if (!crit)
		    return setInvalidParam(this.error,"rule[" + i + "].rules",l,"Invalid routing rule: no matching criteria",false);
		sect.addValue(crit,action);
	    }
	}
	if ("" != cont)
	    return setInvalidParam(this.error,"rule[" + i + "].rules",cont,"Missing continuation line after '\\'",false);
    }

    return true;
};

RegexConfig.set = function(name,params,msg,setNode)
{
    var regex = new RegexConfig;
    if (setNode && !params) {
        if (params == undefined)
           return { name: "regexroute" };
	regex.prepareConfig(msg);
	regex.enable = false;
	if (!regex.saveConfig())
	    return regex.error;
	return { name: "regexroute" };
    }
    if (!checkJson(regex.error,params,msg.json))
	return regex.error;
    regex.prepareConfig(msg);
    if (!regex.buildConfig(params))
	return regex.error;
    if (!regex.saveConfig())
	return regex.error;
    if (!setNode && parseBool(params.restart)) {
	Engine.output(name.toUpperCase,"restart on regexroute config:",msg.received);
	Engine.restart();
    }
    name = name.toLowerCase();
    return { name: "regexroute", object: name};
};


// Configure custom Javascripts
function ScriptConfig()
{
    this.error = new Object;
    this.jsUser = null;
    this.scripts = new Object;
    this.received = "";
}
ScriptConfig.prototype = new Object;

ScriptConfig.prototype.prepareConfig = function(msg)
{
    this.jsUser = prepareConf("js-user-config",msg);
    if ("" != msg.received)
	this.received = "// Updated: " + msg.received;
};

ScriptConfig.prototype.saveConfig = function()
{
    if (!checkUnlocked(this.error,[this.jsUser]))
	return false;
    if (!saveConf(this.error,this.jsUser))
	return false;
    var ok = true;
    for (var f in this.scripts) {
	if (File.setContent(f,this.scripts[f]) < 0) {
	    this.error.error = 501;
	    if ("" == this.error.reason)
		this.error.reason = "Error writing " + f;
	    else
		this.error.reason += ", " + f;
	    ok = false;
	}
    }
    return ok;
};

ScriptConfig.prototype.buildConfig = function(params)
{
    if (!params.scripts)
	return true;
    if (!Array.isArray(params.scripts))
	return setInvalidParam(this.error,"scripts",null,"Must be an array",false);
    if (!params.scripts.length)
	return setInvalidParam(this.error,"scripts",null,"Empty array",false);
    var base = Engine.runParams("configpath") + "/";
    var scripts = { };
    for (var i = 0; i < params.scripts.length; i++) {
	var r = params.scripts[i];
	if ("object" != typeof r)
	    return setInvalidParam(this.error,"scripts[" + i + "]",null,"Must be an object",false);
	if (isEmpty(r.script))
	    return setMissingParam(this.error,"scripts[" + i + "].script",false);
	var name = false;
	if (isFilled(r.name)) {
	    name = r.name;
	    if ("string" != typeof name)
		return setInvalidParam(this.error,"scripts[" + i +"].name",null,"Must be string",false);
	    if (!name.match(/^[A-Za-z][[:alnum:]_-]+$/))
		return setInvalidParam(this.error,"scripts[" + i +"].name",null,"Invalid script name",false);
	}
	else
	    name = "" + i;
	name = "user-" + name;
	var file = base + name + ".js";
	var jsString = r.script;
	if ("string" != typeof jsString)
	    return setInvalidParam(this.error,"scripts[" + i + "].script",null,"Must be string",false);
	var hasher = new Hasher("md5");
	hasher.update(r.script,false);
	var md5 = hasher.hexDigest();

	var oldContent = File.getContent(file,false,262144);
	var oldMd5 = "";
	if ("string" == typeof oldContent) {
	    if (oldContent.startsWith("// Updated: "))
		oldContent = oldContent.substr(oldContent.indexOf("\n") + 1);
	    if (oldContent.startsWith("// Content MD5: ")) {
		var pos = oldContent.indexOf("\n\n");
		if (pos > 0) {
		    oldMd5 = oldContent.substr(16,pos - 16);
		    oldContent = oldContent.substr(oldContent.indexOf("\n\n") + 2);
		}
	    }
	    if (md5 == oldMd5) // content is the same
		continue;
	}

	if (md5 == oldMd5) // content is the same
	    continue;
	else
	    this.scripts[file] = this.received + "\n// Content MD5: " + md5 + "\n\n" + r.script;

	this.jsUser.setValue("scripts",name,file);
    }

    return true;
};

ScriptConfig.set = function(name,params,msg,setNode)
{
    var js = new ScriptConfig;
    if (setNode && !params) {
        if (params == undefined)
           return { name: "javascript" };
	js.prepareConfig(msg);
	js.enable = false;
	if (!js.saveConfig())
	    return js.error;
	return { name: "javascript" };
    }
    if (!checkJson(js.error,params,msg.json))
	return js.error;
    js.prepareConfig(msg);
    if (!js.buildConfig(params))
	return js.error;
    if (!js.saveConfig())
	return js.error;
    if (!setNode && parseBool(params.restart)) {
	Engine.output(name.toUpperCase,"restart on javascript config:" + msg.received);
	Engine.restart();
    }
    name = name.toLowerCase();
    return { name: "javascript", object: name};
};


/* vi: set ts=8 sw=4 sts=4 noet: */
