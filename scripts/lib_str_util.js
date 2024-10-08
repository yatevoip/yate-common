/**
 * lib_str_util.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * String utility functions library for Javascript
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2013-2023 Null Team
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

// Check if a parameter is present (defined and not null)
function isPresent(val)
{
    return (undefined !== val && null !== val);
}

// Check if a parameter is missing (undefined or null)
function isMissing(val)
{
    return (undefined === val || null === val);
}

// Check if a parameter is filled (defined, not empty and not null)
function isFilled(val)
{
    return ("" != val && null !== val);
}

// Check if a parameter is empty (undefined, empty string or null)
function isEmpty(val)
{
    return ("" == val || null === val);
}

// Removes spaces in a hex string, turn it to lower case
function hexPack(str)
{
    if (isMissing(str))
	return null;
    str = str.toLowerCase();
    if (str.indexOf(" ") < 0)
	return str;
    str = str.split(" ");
    return str.join("");
}

// Insert spaces in a hex string, turn it to upper case
function hexStuff(str)
{
    if (isMissing(str))
	return null;
    str = str.toUpperCase();
    var ret = "";
    for (var i = 0; i < str.length; i+=2) {
	if ("" != ret)
	    ret += " ";
	ret += str.substr(i,2);
    }
    return ret;
}

// Check if a string is a valid hexified string with a given minimum length.
// Optionally, a maximum length can also be checked
// Given length is in bytes
// Function calls internally hexPack
function isValidHex(str,minLen,maxLen)
{
    if (isMissing(str) || isNaN(minLen))
	return false;
    str = hexPack(str);
    if ((str.length & 1) || !(str.match(/^[[:xdigit:]]+$/)))
	return false;
    if (str.length < 2 * minLen)
	return false;
    if (isMissing(maxLen))
	return true;
    if (isNaN(maxLen))
	return false;

    return (str.length <= 2 * maxLen);
}

// Encode a single byte in hexadecimal swapped nibble format
function binSwap(b)
{
    b = b.toString(16,2);
    return b.charAt(1) + b.charAt(0);
}

// Encode a 0-99 number in BCD swapped nibble format
function bcdSwap(b)
{
    b = b.toString(10,2);
    return b.charAt(1) + b.charAt(0);
}

// Decode a byte from hexadecimal at a specified offset in a string
function hexByte(str,offs)
{
    return parseInt(str.substr(offs,2),16);
}

// Remove quotes around a string
function cutQuotes(str)
{
    if (isMissing(str))
	return null;
    if ('"' == str.charAt(0) && '"' == str.charAt(str.length - 1))
	return str.substr(1,str.length - 2);
    return str;
}

// Remove angular parantheses around a string
function cutAngles(str)
{
    if (isMissing(str))
	return null;
    if ('<' == str.charAt(0) && '>' == str.charAt(str.length - 1))
	return str.substr(1,str.length - 2);
    return str;
}

// Parse a string as boolean value
function parseBool(str,defVal)
{
    if (true === str || false === str)
	return str;
    switch ("" + str) {
	case "false":
	case "no":
	case "off":
	case "disable":
	case "f":
	    return false;
	case "true":
	case "yes":
	case "on":
	case "enable":
	case "t":
	    return true;
    }
    if (undefined === defVal)
	return false;
    return defVal;
}

// Convert a number to MSISDN (international format)
function toMSISDN(num,cc,ton,skipCC)
{
    if (isEmpty(num))
	return null;
    // E.164 +CCNNNN
    if (num.match(%+z.%))
	return num.substr(1);
    // If Type Of Number is known
    switch (ton) {
	case 0x11:
	case 0x50: // alphanumeric, npi unknown
	case 0x51: // alphanumeric, npi isdn
	case 0x58: // alphanumeric, npi national
	case "alphanumeric":
	case "international":
	    return num;
	case 0x21:
	case "national":
	    // ISDN national
	    return cc + num;
    }
    // Try the national dialing rules
    var ncc = cc + ":" + num;
    for (var r of toMSISDN.rules) {
	var m = ncc.match(r.match);
	if (!m)
	    m = num.match(r.match);
	if (m) {
	    if (isEmpty(r.repl))
		return null;
	    m["cc"] = cc;
	    return Engine.replaceParams(r.repl,m);
	}
    }
    // NNNNN various national
    if (cc && num.match(%zxxx.%)) {
	if (skipCC) {
	    // Check if someone entered CC without + in front
	    if (num.startsWith(cc))
		return num;
	}
	return cc + num;
    }
    // Else is not a number or too short to be a MSISDN
    return null;
}

// Default national dialing rules, may be altered or replaced
toMSISDN.rules = [
    { match:%591:0010(zxxx.)%,  repl:"${1}"       }, // 0010CCNNN Bolivia
    { match:%61:0011(zxxx.)%,   repl:"${1}"       }, // 0011CCNNN Australia default carrier
    { match:%234:009(zxxx.)%,   repl:"${1}"       }, // 009CCNNN Nigeria
    { match:%685:0(zx.)%,       repl:"${1}"       }, // 0CCNNN Samoa
    { match:%53:119(zx.)%,      repl:"${1}"       }, // 119CCNNN Cuba
    { match:%592:001(zx.)%,     repl:"${1}"       }, // 001CCNNN Guyana
    { match:%976:001(zx.)%,     repl:"${1}"       }, // 001CCNNN Mongolia
    { match:%1:1(nx{9})%,       repl:"${cc}${1}"  }, // 1NNN NANP traditional
    { match:%1:(nx{9})%,        repl:"${cc}${1}"  }, // NNN NANP area code
    { match:%7:8(zx{9})%,       repl:"${cc}${1}"  }, // 8NNN Russia national
    { match:%00(1zxx.)%,        repl:"${1}"       }, // 001NNN ITU dialing NANP
    { match:%00(nxxx.)%,        repl:"${1}"       }, // 00CCNNN ITU to non-NANP
    { match:%000(zxxx.)%,       repl:"${1}"       }, // 000CC Kenya, Tanzania, Uganda, malformed
    { match:%011(nxxx.)%,       repl:"${1}"       }, // 011CCNNN NANP except to NANP itself
    { match:%010(zxxx.)%,       repl:"${1}"       }, // 010CCNNN Japan
    { match:%0(zx.)%,           repl:"${cc}${1}"  }, // 0NNN various national
    { match:%0000(zxxx.)%,      repl:"${1}"       }, // 0000CCNNN malformed international
];

// Compute the Luhn check digit for IMEI
function computeLuhn(num,append)
{
    if (isEmpty(num))
	return "";
    var n = 0;
    for (var i = 0; i < num.length; i++) {
	var d = parseInt(num.charAt(i));
	if (i & 1) {
	    d *= 2;
	    if (d >= 10)
		d -= 9;
	}
	n += d;
    }
    if (!append)
	num = "";
    n %= 10;
    if (!n)
	return num + "0";
    return num + (10 - n);
}

// Helper that returns a left or right aligned fixed length string
function strFix(str,len,pad)
{
    if (null === str)
	str = "";
    if ("" == pad)
	pad = " ";
    if (len < 0) {
	// right aligned
	len = -len;
	if (str.length >= len)
	    return str.substr(str.length - len);
	while (str.length < len)
	    str = pad + str;
    }
    else {
	// left aligned
	if (str.length >= len)
	    return str.substr(0,len);
	while (str.length < len)
	    str += pad;
    }
    return str;
}

// Helper that returns a left or right aligned fixed length integer
function numFix(num,len)
{
    if (isNaN(num))
	return strFix(num,len);
    var tmp = "" + num;
    if (tmp.length <= Math.abs(len))
	return strFix(tmp,len);
    tmp = tmp.length - Math.abs(len);
    if (tmp > 7)
	tmp = ((num + 500000000000) / 1000000000000) + " T";
    else if (tmp > 4)
	tmp = ((num + 500000000) / 1000000000) + " G";
    else if (tmp > 1)
	tmp = ((num + 500000) / 1000000) + " M";
    else
	tmp = ((num + 500) / 1000) + " K";
    if (tmp.length > Math.abs(len))
	return strFix("#",len,"#");
    return strFix(tmp,len);
}

// Validate a number. Convert 'val' to number if not already done
// Return defVal if given value is not a number or outside given interval and not clamped
function checkInt(val,defVal,minVal,maxVal,clamp)
{
    val = 1 * val;
    if (isNaN(val))
	return defVal;
    if (!isNaN(minVal) && val < minVal) {
	if (undefined === clamp || clamp)
	    return minVal;
	return defVal;
    }
    if (!isNaN(maxVal) && val > maxVal) {
	if (undefined === clamp || clamp)
	    return maxVal;
	return defVal;
    }
    return val;
}

// Format time in milliseconds as seconds with 3 decimal places
// Format time as hours (hrs is boolean true) or minutes (hrs is boolean false)
function fmtTime(msec,hrs)
{
    msec *= 1;
    if (isNaN(msec))
	return "0";
    if (true === hrs) {
	hrs = msec / 3600000;
	msec = (Math.abs(msec) % 3600000) / 1000;
	return hrs + ":" + strFix(msec / 60,-2,"0") + ":" + strFix(msec % 60,-2,"0");
    }
    if (false === hrs) {
	msec /= 1000;
	return (msec / 60) + ":" + strFix((Math.abs(msec) % 60),-2,"0");
    }
    return (msec / 1000) + "." + strFix((Math.abs(msec) % 1000),-3,"0");
}

// Format date and time: YYYY-MM-DD H:M:S[.mmm]
function fmtDateTime(sec,msec)
{
    sec *= 1;
    if (isNaN(sec))
	return "";
    var d = new Date(sec * 1000);
    d = d.toJSON();
    var pos = d.indexOf("T");
    d = d.substr(0,pos) + " " + d.substr(pos + 1,8);
    msec *= 1;
    if (isNaN(msec) || msec < 0)
	return d;
    return d + strFix(msec,-3,"0");
}

// Helper that returns "yes" or "no" for boolean input, optionally pads to specified length
function yesNo(val,len,pad)
{
    if (!isNaN(1*len))
	return strFix(yesNo(val),len,pad);
    if (val)
	return "yes";
    return "no";
}

// Helper that returns "on" or "off" for boolean input, optionally pads to specified length
function onOff(val,len,pad)
{
    if (!isNaN(1*len))
	return strFix(onOff(val),len,pad);
    if (val)
	return "on";
    return "off";
}

// Perform one command line completion
function oneCompletion(msg,str,part)
{
    if ("" != part && str.indexOf(part) != 0)
	return false;
    var ret = msg.retValue();
    if ("" != ret)
	ret += "\t";
    msg.retValue(ret + str);
    return true;
}

// Perform command line completion from object properties or array entries
// 'key' present Perform object
function multiCompletion(msg,obj,part,key,maxComplete)
{
    if (!(obj && "object" == typeof obj))
	return;
    if (maxComplete > 0)
	;
    else
	maxComplete = 0xffffffff;
    var ok = false;
    if (key) {
	// Complete object property values
	for (var s of obj) {
	    if (!oneCompletion(msg,s[key],part))
		continue;
	    ok = true;
	    if (--maxComplete <= 0)
		break;
	}
    }
    else if (Array.isArray(obj)) {
	// Complete array of strings
	for (var s of obj) {
	    if (!oneCompletion(msg,s,part))
		continue;
	    ok = true;
	    if (--maxComplete <= 0)
		break;
	}
    }
    else {
	// Complete object property names
	for (var s in obj) {
	    if (!oneCompletion(msg,s,part))
		continue;
	    ok = true;
	    if (--maxComplete <= 0)
		break;
	}
    }
    return ok;
}

// Prepare next page output parameters in command message
function setPaging(msg,length,header)
{
    if (isNaN(msg.cmd_height) || (msg.cmd_height < 10))
	return;
    if (isNaN(header))
	header = 3;
    msg.cmd_header = header;
    if (isNaN(msg.cmd_offset))
	msg.cmd_offset = 0;
    var winLen = msg.cmd_height - header;
    if (length < winLen)
	msg.cmd_finish = msg.cmd_offset + length;
    if (length > 0)
	msg.cmd_offset += winLen;
}

// Copy object properties
// list: Optional comma separated list of properties to copy
// prefix: Optional prefix used to match properties (applied before cheking in list)
// skipPrefix: Optional boolean indicating if prefix should be skipped (undefined/null -> true)
function copyObjProps(dest,src,list,prefix,skipPrefix)
{
    var a = null;
    if (list) {
	a = list.split(",");
	if (!a)
	    return;
    }
    for (var p in src) {
	if (prefix) {
	    if (!p.startsWith(prefix))
		continue;
	    if (false !== skipPrefix) {
		var pDest = p.substr(prefix.length);
		if (pDest) {
		    if (!a)
			dest[pDest] = src[p];
		    else if (a.includes(p))
			dest[pDest] = src[p];
		}
		continue;
	    }
	}
	if (!a)
	    dest[p] = src[p];
	else if (a.includes(p))
	    dest[p] = src[p];
    }
}

// Helper function to copy some non-null parameters from one object to another
// Prefixes in front of the name:
//  ! indicates boolean conversion
//  # indicates numeric conversion
//  % indicates hexadecimal number
//  & indicates splitting to an array
function copyNonNull(dest,src,list,prefix)
{
    var c = 0;
    for (var i = 0; i < list.length; i++) {
	var n = list[i];
	var t = n.substr(0,1);
	switch (t) {
	    case "!":
	    case "#":
	    case "%":
	    case "&":
		n = n.substr(1);
		break;
	    default:
		t = "";
	}
	var v = src[prefix + n];
	if (isPresent(v)) {
	    c++;
	    switch (t) {
		case "!":
		    dest[n] = !!v;
		    break;
		case "#":
		    dest[n] = 1*v;
		    break;
		case "%":
		    v = 1*v;
		    dest[n] = "0x" + v.toString(16);
		    break;
		case "&":
		    v = v.trim();
		    if ("" == v) {
			c--;
			continue;
		    }
		    v = v.split(",");
		    for (var j = 0; j < v.length; j++) {
			var s = v[j];
			v[j] = s.trim();
		    }
		    dest[n] = v;
		    break;
		default:
		    dest[n] = "" + v;
	    }
	}
    }
    return c;
}

// Build date time from timestamp & format
// dd/mm/yyyy-HH:mm:ss  -  for local format
// dd/mm/yyyy-HH:mm:ssZ -  for ucn format
// dd/mm/yyyy-HH:mm:ssGMT+/-N(N) - local_tz format
function buildDate(timestamp, format)
{
    if (!format) {
	format = "local_tz";
    }

    var time = timestamp * 1000;
    time = new Date(time);

    if ("utc"===format) {
	var tz = time.getTimezoneOffset();
	timestamp = timestamp * 1000 + tz * 1000 * 60;
	time = new Date(timestamp);
    }

    // in case of unknown format, local will be returned
    var month = time.getMonth() + 1;
    var date = strFix(time.getDate(),-2,"0") + "/" + strFix(month,-2,"0") + "/" + time.getFullYear() + " " +
	strFix(time.getHours(),-2,"0") + ":" + strFix(time.getMinutes(),-2,"0") + ":" + strFix(time.getSeconds(),-2,"0");

    switch (format) {
	case "local_tz":
	    var tz = time.getTimezoneOffset() / -60;
	    date += "GMT";
	    if (tz<0)
		date += "-";
	    else if (tz>0)
		date += "+";
	    if (tz!=0)
		date += tz;
	    break;
	case "utc":
	    date += "Z";
    }
   
    return date;
}

// This method splits a String object into an array of strings by separating the string into substrings.
// Extends string.split() -  sep can be more than one char
function strSplit(string,sep)
{
    if (sep===undefined || sep.length==0)
	return [string];

    if (sep.length==1)
	return string.split(sep);

    var arr = [];
    while(true) {
	var pos = string.indexOf(sep);
	if (pos==-1) {
	    arr.push(string);
	    break;
	}
	arr.push(string.substr(0,pos));
	string = string.substr(pos + sep.length);
    }

    return arr;
}

/* vi: set ts=8 sw=4 sts=4 noet: */
