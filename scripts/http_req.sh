#! /bin/bash

# http_req.sh
# This file is part of the YATE Project http://YATE.null.ro
#
# Yet Another Telephony Engine - a fully featured software PBX and IVR
# Copyright (C) 2014-2017 Null Team
#
# This software is distributed under multiple licenses;
# see the COPYING file in the main directory for licensing
# information for this specific distribution.
#
# This use of this software may be subject to additional restrictions.
# See the LEGAL file in the main directory for details.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.


# Global Yate script that performs HTTP requests
#
# Install in extmodule.conf
#
# [scripts]
# /path/to/http_req.sh
#
# Or, to run multiple instances
#
# [scripts]
# /path/to/http_req.sh=0
# /path/to/http_req.sh=1
# /path/to/http_req.sh=2
# ...

# install in Yate and run main loop
if [ -n "$1" ]; then
    echo "%%>setlocal:trackparam:http_req_$1"
    echo "%%>install:95:http.request:instance:$1"
else
    echo "%%>setlocal:trackparam:http_req"
    echo "%%>install:95:http.request"
fi
echo "%%>setlocal:restart:true"
while read -r REPLY; do
    case "$REPLY" in
	%%\>message:*)
	    # http.request handling
	    id="${REPLY#*:*}"; id="${id%%:*}"
	    params=":${REPLY#*:*:*:*:*:}:"
	    opt=()
	    len=0
	    # extract parameters, assume we don't need unescaping
	    sync="${params#*:wait=}"; sync="${sync%%:*}"
	    url="${params#*:url=}"; url="${url%%:*}"
	    # unescape just the % and : characters
	    url=`echo "$url" | sed 's/%z/:/g; s/%%/%/g'`
	    dbg="${params#*:debug=}"; dbg="${dbg%%:*}"
	    case "X$dbg" in
		Xtrue|Xyes|Xon|Xenable|X1)
		    dbg="-S"
		    ;;
		Xfull|Xdebug)
		    dbg="-d"
		    ;;
		*)
		    dbg="-q"
		    ;;
	    esac
	    tmp="${params#*:timeout=}"; tmp="${tmp%%:*}"
	    tmp=`echo "$tmp" | sed 's/%z/:/g; s/%%/%/g; s/ //g'`
	    if [ "$tmp" -ge "100" 2>/dev/null ]; then
		tmp=`echo "$tmp" | sed 's/^\(.*\)\([0-9]\{3\}\)$/\1.\2/'`
		opt[$((len++))]="--timeout=$tmp"
	    fi
	    tmp="${params#*:tries=}"; tmp="${tmp%%:*}"
	    tmp=`echo "$tmp" | sed -n '/^[1-9]$/p'`
	    test -n "$tmp" || tmp="1"
	    opt[$((len++))]="--tries=$tmp"
	    tmp="${params#*:accept=}"; tmp="${tmp%%:*}"
	    tmp=`echo "$tmp" | sed 's/%z/:/g; s/%%/%/g; s/ //g'`
	    test -n "$tmp" && opt[$((len++))]="--header=Accept:$tmp"
	    tmp="${params#*:body=}"; tmp="${tmp%%:*}"
	    if [ -n "$tmp" ]; then
		tmp=`echo "$tmp" | sed 's/%z/:/g; s/%J/\n/g; s/%M/\r/g; s/%I/\t/g; s/%%/%/g'`
		opt[$((len++))]="--post-data=$tmp"
		tmp="${params#*:type=}"; tmp="${tmp%%:*}"
		tmp=`echo "$tmp" | sed 's/%z/:/g; s/%%/%/g; s/ //g'`
		test -n "$tmp" && opt[$((len++))]="--header=Content-Type:$tmp"
	    fi
	    tmp="${params#*:header=}"; tmp="${tmp%%:*}"
	    tmp=`echo "$tmp" | sed 's/%z/:/g; s/%%/%/g; s/ //g'`
	    test -n "$tmp" && opt[$((len++))]="--header=$tmp"
	    tmp="${params#*:agent=}"; tmp="${tmp%%:*}"
	    if [ -n "$tmp" ]; then
		tmp=`echo "$tmp" | sed 's/%z/:/g; s/%%/%/g; s/^ \+//; s/ \+$//'`
		test -n "$tmp" && opt[$((len++))]="--user-agent=$tmp"
	    fi
	    resp=""
	    case "X$sync" in
		Xtrue|Xyes|Xon|Xenable|X1)
		    mark="err$$_"
		    multi="${params#*:multiline=}"; multi="${multi%%:*}"
		    case "X$multi" in
			Xtrue|Xyes|Xon|Xenable|X1)
			    # return all lines of response escaped as a single string
			    resp=`(wget $dbg -O - "${opt[@]}" "${url}" || echo "$mark$?_") </dev/null | sed 's/%/%%/g; s/:/%z/g; s/\t/%I/g; s/\r/%M/g; {:n;N;s/\n/%J/g;t n}; s/[[:cntrl:]]//g'`
			    ;;
			*)
			    # keep only first line of response and escape it
			    resp=`(wget $dbg -O - "${opt[@]}" "${url}" || echo "$mark$?_") </dev/null | head -1 | sed 's/[[:cntrl:]]//g; s/%/%%/g; s/:/%z/g'`
			    ;;
		    esac
		    case "X$resp" in
			X$mark*)
			    resp="${resp#$mark}"
			    resp=":error=wget-${resp%%_}"
			    ;;
			X)
			    resp=":"
			    ;;
		    esac
		    ;;
		*)
		    # execute asynchronously, don't care if succeeds or fails
		    wget $dbg -O - "${opt[@]}" "${url}" </dev/null >/dev/null &
		    ;;
	    esac
	    if [ -n "$resp" ]; then
		echo "%%<message:$id:true::$resp"
	    else
		echo "%%<message:$id:false::"
	    fi
	    ;;
    esac
done

# vi: set ts=8 sw=4 sts=4 noet:
