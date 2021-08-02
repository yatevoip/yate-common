/**
 * lib_url_util.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * URL and HTTP utility functions library for Javascript
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2016-2017 Null Team
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

user_agent = "";

function encodeURIComponent(str)
{
    if ("" == str)
	return str;
    var esc = "";
    while ("" != str) {
	var tmp = str.match("^([[:alnum:]*'()~!_.-]+)(.*)$");
	if (tmp) {
	    esc += tmp[1];
	    str = tmp[2];
	}
	else {
	    tmp = str.charCodeAt(0);
	    esc += "%" + tmp.toString(16,2);
	    str = str.substr(1);
	}
    }
    return esc;
}

function httpRequest(url,sync,accept,oneline,extra,return_mess)
{
    if (isEmpty(url))
	return null;
    if (undefined === accept)
	accept = "text/plain;charset=UTF-8";
    var m = new Message("http.request",false,extra);
    m.url = url;
    if (isFilled(accept))
	m.accept = accept;
    m.multiline = !oneline;
    if (isFilled(user_agent))
	m.agent = user_agent;
    if (!sync) {
	m.enqueue();
	return null;
    }
    m.wait = true;
    if (!m.dispatch(true))
	return null;
    if (m.error)
	return null;

    if (!!return_mess)
	return m;
    return "" + m.retValue();
};

/* vi: set ts=8 sw=4 sts=4 noet: */
