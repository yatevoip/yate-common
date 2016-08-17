/**
 * generic_validations.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2016 Null Team
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

//#pragma trace "cachegrind.out.ybts_fields"

/* ------------ Generic validation functions ------------ */


// Check a field value if exists in an array
function checkValidSelect(error,field_name,field_value,select_array,section_name)
{
    if (select_array.indexOf(field_value) < 0) {
	error.reason = "The field '" + field_name + "' is not valid: '" + field_value + "', is different from the allowed values in section '" + section_name + "'.";
	error.error = 401;
	return false;
    }
    return true;
}

// Validate a checkbox value
function checkOnOff(error,field_name,field_value,section_name)
{
   if (parseBool(field_value,null) === null) {
	error.reason =  "Invalid checkbox value '" + field_value + "' for '" + field_name + "' in section '" + section_name + "'.";
	error.error = 401;
	return false;
    }
    return true;
}

//Validate a field to be in a range or a regex
function checkFieldValidity(error,section_name,field_name,field_value,min,max,regex,fixed)
{
    if (min !== undefined && max !== undefined)  {
        field_value = parseInt(field_value);
	if (isNaN(field_value)) {
	    error.reason = "Field '" + field_name + "' is not a valid number: " + field_value + " in section '" + section_name + "'.";
	    error.error = 401;
	    return false;
	}

	if (field_value < min || field_value > max) {
	    error.reason = "Field '" + field_name + "' is not valid: '" + field_value + "'. It has to be smaller then " + max + " and greater then " + min + " in section '" + section_name + "'.";
	    error.error = 401;
	    return false;
	}
    }

    if (regex != undefined) {
	var str = new RegExp(regex);
	if (!str.test(field_value)) {
	    error.reason = "Field '" + field_name + "' is not valid: '" + field_value + "' in section '" + section_name + "'.";
	    error.error = 401;
	    return false;
	}
    }

    if (fixed != undefined && fixed != field_value) {
	  error.reason = "Field '" + field_name + "' is not valid: '" + field_value + "'. It has to be " + fixed + " in section '" + section_name + "'.";
	  error.error = 401;
	  return false;
    }

    return true;
}

// Test if a parameter value is missing
function isParamMissing(error,param,value,section_name)
{
    if (isPresent(value))
	return false;
    setMissingParam(error,param,section_name);
    return true;
}

// Set the error object for a missing param 
function setMissingParam(error,param,section_name)
{
    error.reason = "Missing required '" + param + "' parameter in section '" + section_name + "'.";
    error.error = 402;
    return false;
}

// Test an IP validity
function checkValidIP(error,field_name,field_value,section_name)
{
//  var str = /^([0-9]{1,3}\.){3}[0-9]{1,3}$/;
//  if (!str.test(field_value)) {

    if (!field_value.length)
	return true;

    if ((field_value.indexOf(".") == 1 && field_value.indexOf(":") == -1) || !DNS.pack(field_value)) {
	error.reason = "Field '" + field_name + "' is not a valid IP address: '" + field_value + "' in section '" + section_name + "'";
	error.error = 401;
	return false;	
    }	
    return true;
}

/**
 * Validate integer value
 */
function checkValidInteger(error,field_name,field_value,section_name)
{
    if (!field_value.length)
	return true;
    
    field_value = parseInt(field_value);
    if (isNaN(field_value)) {
	error.reason = "Field '" + field_name + "' is not numeric: '" + field_value + "' in section '" + section_name + "'.";
	error.error = 401;
	return false;
    }
    return true;
}

/**
 * Validate a value if exists, is a number and is positive
 */ 
function validatePositiveNumber(error,param_name,param_value,section_name)
{
    var param_value = parseInt(param_value);
    if (!isEmpty(param_value) && isNaN(param_value) || param_value < 0) {
	error.reason = "Field '" + param_name + "' should be numeric in section '" + section_name + "'.";
	error.error = 401;
	return false;
    }
    return true;
}

/**
 * Validate geo location: Latitude, longitude dd.dddddd,ddd.dddddd format 
 */ 
function checkValidGeoLocation(error,field_name,field_value,section_name)
{
    var regexp = new RegExp(/^[-]?(([0-8]?[0-9])\.([0-9]+))|(90(\.0+)?),[-]?((((1[0-7][0-9])|([0-9]?[0-9]))\.([0-9]+))|180(\.0+)?)$/);
    if (!regexp.test(field_value)) {
	error.reason = "Field '" + param_name + "' is not a valid geo location: latitude, longitude dd.dddddd,ddd.dddddd format" + " in section '" + section_name +  "'.";
	error.error = 401;
	return false;
    }
    return true;
}

/**
 * Validate float number
 */ 
function checkValidFloat(error,field_name,field_value,section_name)
{
    if (!field_value.length)
	return true;
    var regexp = new RegExp(/^[-]?([1-9][0-9]*|0)(\.[0-9]+)?$/);
    if (!regexp.test(field_value)) {
	error.reason = "Field '" + param_name + "' is not a valid float number" + " in section '" + section_name +  "'.";
	error.error = 401;
	return false;
    }
    return true;
}

/**
 * Validate positive float number
 */ 
function checkPositiveFloat(error,field_name,field_value,section_name)
{
    if (!field_value.length)
	return true;
    var regexp = new RegExp(/^([1-9][0-9]*|0)(\.[0-9]+)?$/);
    if (!regexp.test(field_value)) {
	error.reason = "Field '" + param_name + "' is not a positive float number" + " in section '" + section_name +  "'.";
	error.error = 401;
	return false;
    }
    return true;
}
