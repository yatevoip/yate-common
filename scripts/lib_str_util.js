/**
 * lib_str_util.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * String utility functions library for Javascript
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2013-2016 Null Team
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
    switch (str) {
	case false:
	case "false":
	case "no":
	case "off":
	case "disable":
	case "f":
	    return false;
	case true:
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
    switch (ton) {
	case 0x11:
	case "international":
	    return num;
	case 0x21:
	case "national":
	    // ISDN national
	    return cc + num;
    }
    switch (num) {
	case %+.%:
	    // E.164 +CCNNNN
	    return num.substr(1);
	case %0000zxxx.%:
	    // Malformed international number 0000CCNNN
	case %0010zxxx.%:
	    // Bolivia 0010CCNNN
	case %0011zxxx.%:
	    // Australia default carrier 0011CCNNN
	    return num.substr(4);
	case %00zxxx.%:
	    // ITU 00CCNNN
	    return num.substr(2);
	case %000zxxx.%:
	    // Kenya, Tanzania, Uganda, some malformed numbers 000CCNNN
	case %011zxxx.%:
	    // USA 011CCNNN
	    return num.substr(3);
	case %0zx.%:
	    // 0NNN various national
	    return cc + num.substr(1);
	case %zx.%:
	    // NNN various national
	    if (cc && skipCC) {
		// Check if someone entered CC without + in front
		if (num.startsWith(cc))
		    return num;
	    }
	    return cc + num;
	// Else is not a number or too short to be a MSISDN
    }
    return null;
}

// Compute the Luhn check digit for IMEI
function computeLuhn(num)
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
    n %= 10;
    if (!n)
	return "0";
    return "" + (10 - n);
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
    if (tmp.length > len)
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
function fmtTime(msec)
{
    msec *= 1;
    if (isNaN(msec))
	return "0";
    return (msec / 1000) + "." + strFix((Math.abs(msec) % 1000),-3,"0");
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

/* vi: set ts=8 sw=4 sts=4 noet: */
