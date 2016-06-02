#! /bin/bash

# http_req.sh
# This file is part of the YATE Project http://YATE.null.ro
#
# Yet Another Telephony Engine - a fully featured software PBX and IVR
# Copyright (C) 2014-2016 Null Team
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

# install in Yate and run main loop
echo "%%>setlocal:trackparam:http_req"
echo "%%>install:95:http.request"
echo "%%>setlocal:restart:true"
while read -r REPLY; do
    case "$REPLY" in
	%%\>message:*)
	    # http.request handling
	    id="${REPLY#*:*}"; id="${id%%:*}"
	    params=":${REPLY#*:*:*:*:*:}:"
	    # extract parameters, assume we don't need unescaping
	    sync="${params#*:wait=}"; sync="${sync%%:*}"
	    url="${params#*:url=}"; url="${url%%:*}"
	    acc="${params#*:accept=}"; acc="${acc%%:*}"
	    # unescape just the % and : characters
	    url=`echo "$url" | sed 's/%z/:/g; s/%%/%/g'`
	    acc=`echo "$acc" | sed 's/%z/:/g; s/%%/%/g; s/ //g'`
	    test -n "$acc" && acc="--header=Accept:$acc"
	    resp=""
	    case "X$sync" in
		Xtrue|Xyes|Xon|Xenable|X1)
		    mark="err$$_"
		    multi="${params#*:multiline=}"; multi="${multi%%:*}"
		    case "X$multi" in
			Xtrue|Xyes|Xon|Xenable|X1)
			    # return all lines of response escaped as a single string
			    resp=`(wget -q -O - $acc "${url}" || echo "$mark$?_") </dev/null | sed 's/%/%%/g; s/:/%z/g; s/\t/%I/g; s/\r/%M/g; {:n;N;s/\n/%J/g;t n}; s/[[:cntrl:]]//g'`
			    ;;
			*)
			    # keep only first line of response and escape it
			    resp=`(wget -q -O - $acc "${url}" || echo "$mark$?_") </dev/null | head -1 | sed 's/[[:cntrl:]]//g; s/%/%%/g; s/:/%z/g'`
			    ;;
		    esac
		    case "X$resp" in
			X$mark*)
			    resp="${resp#$mark}"
			    resp=":error=wget-${resp%%_}"
			    ;;
		    esac
		    ;;
		*)
		    # execute asynchronously, don't care if succeeds or fails
		    wget -q -O - $acc "${url}" </dev/null >/dev/null &
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
