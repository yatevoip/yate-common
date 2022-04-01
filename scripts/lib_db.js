/**
 * lib_db.js
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * Database helper functions library for Javascript
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2012-2016 Null Team
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

// Make a SQL query, return the holding message or null if the query failed
function sqlQuery(query,account,async,results,warn)
{
    if (true === sqlQuery.debug)
	Engine.output(query);
    else if ((sqlQuery.debug > 0) && (sqlQuery.debug <= 10))
	Engine.debug(sqlQuery.debug,query);
    var m = new Message("database");
    if (undefined === account)
	account = dbacc;
    if (undefined === async)
	async = !!sqlQuery.async;
    m.account = account;
    m.query = query;
    if (false === results)
	m.results = false;
    if ("enqueue" === async) {
	if (m.enqueue())
	    return true;
	if (false !== warn)
	    Engine.debug(Engine.DebugWarn,"Query failed to be queued on '" + account + "': " + query);
	return false;
    }
    if (m.dispatch(async)) {
	if (!m.error)
	    return m;
	if (false !== warn)
	    Engine.debug(Engine.DebugWarn,"Query " + m.error + " on '" + account + "': " + query);
    }
    else if (false !== warn)
	Engine.debug(Engine.DebugWarn,"Query not handled by '" + account + "': " + query);
    return null;
}

// Make a SQL query, return 1st column in 1st row, null if query failed or returned no records
// Return undefined if dbFail is set and database query succeeds with empty result
function valQuery(query,async,account,dbFail)
{
    var res = sqlQuery(query,account,async);
    if (!res)
	return null;
    if (dbFail) {
	if (null !== (res = res.getResult(0,0)))
	    return res;
	return undefined;
    }
    return res.getResult(0,0);
}

// Make a SQL query, return 1st row as Object, null if query failed or returned no records
// Return undefined if dbFail is set and database query succeeds with empty result
function rowQuery(query,async,account,dbFail)
{
    var res = sqlQuery(query,account,async);
    if (!res)
	return null;
    if (dbFail) {
	if (res = res.getRow(0))
	    return res;
	return undefined;
    }
    return res.getRow(0);
}

// Make a SQL query, return 1st column as Array, null if query failed or returned no records
// Return undefined if dbFail is set and database query succeeds with empty result
function colQuery(query,async,account,dbFail)
{
    var res = sqlQuery(query,account,async);
    if (!res)
	return null;
    if (dbFail) {
	if (res = res.getColumn(0))
	    return res;
	return undefined;
    }
    return res.getColumn(0);
}

// Return the type of an SQL account, null if unknown
function sqlType(account)
{
    var res = sqlQuery(undefined,account);
    if (!res)
	return null;
    res = res.dbtype;
    if ("" == res)
	return null;
    return res;
}

function sqlStr(str)
{
    if (null === str || undefined === str)
	return "NULL";
    str += "";
    return "'" + str.sqlEscape() + "'";
}

function sqlNum(num)
{
    if (isNaN(1*num))
	return "NULL";
    num += "";
    return num.sqlEscape();
}

/* vi: set ts=8 sw=4 sts=4 noet: */
