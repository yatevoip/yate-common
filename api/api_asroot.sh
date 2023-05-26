#! /bin/bash

# api_asroot.sh
# This file is part of the YATE Project http://YATE.null.ro
#
# Yet Another Telephony Engine - a fully featured software PBX and IVR
# Copyright (C) 2015-2023 Null Team
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

# Helper script for several API requests that need root access

if [ "$1" = "conntrack-list-nat" ]; then
    if [ -n "$3" ]; then
	/usr/sbin/conntrack -L -p $2 -n | grep $3
    else
	/usr/sbin/conntrack -L -p $2 -n
    fi
    exit $?
fi

if (echo "X$2" | /bin/grep -q -v '^X[[:alnum:]_.-]\+$') 2>/dev/null; then
    echo "Invalid node name" >&2
    exit 1
fi

if [ "X$2" = "Xyate" ]; then
    conf="yate"
    serv="yate"
else
    conf="yate/$2"
    serv="yate-$2"
fi

ext="/usr/share/yate/api/${2}_asroot.sh"
if [ -s "$ext" -a "X0" = X`/usr/bin/stat -c '%u' "$ext" 2>/dev/null` ]; then
    . "$ext"
fi

case "X$1" in
    Xget_node_config)
	dir="/etc/$conf"
	if [ -d "$dir" ]; then
	    cd "$dir"
	    if [ -n "$3" ]; then
		if (echo "X$3" | /bin/grep -q -v '^X[[:alnum:]][[:alnum:]_.-]\+$') 2>/dev/null; then
		    echo "Invalid file name" >&2
		    exit 1
		fi
		if [ -f "$3" ]; then
		    cat "$3"
		else
		    echo "Not a file: $dir/$3" >&2
		    exit 2
		fi
	    else
		/usr/bin/tar -czf - *.conf *.json *.xml *.inc *.php *.js *.sh *.crt *.rpmsave *.csv --ignore-failed-read 2>/dev/null
	    fi
	else
	    echo "Not a directory: $dir" >&2
	    exit 20
	fi
	;;
    Xget_node_logs)
	log="/var/log/$serv"
	if [ -f "$log" ]; then
	    /usr/bin/tail -c 550000 "$log" | /usr/bin/tail -n +2
	else
	    echo "Not a file: $log" >&2
	    exit 2
	fi
	;;
    Xget_node_cdrs)
	conf="/etc/$conf/cdrfile.conf"
	cfg=`/usr/bin/sed ':a;/\\\\$/{N;s/\\\\\\n//;ba}' "$conf"`
	cdr=`echo "$cfg" | /usr/bin/sed -n 's/^ *file *= *\(.*\.tsv\)/\1/p'`
	if [ -z "$cdr" -o "X"`echo "$cdr" | /usr/bin/wc -l` != "X1" ]; then
	    echo "Cannot locate CDR file in $conf" >&2
	    exit 1
	fi
	fmt=`echo "$cfg" | /usr/bin/sed -n 's/$[^{}]\+}/}/g; s/}${/}${|/g; s/${\([^}]\+\)}/\1/g; s/^ *format *= *\(.*\)/\1/p'`
	if [ -z "$fmt" -o "X"`echo "$fmt" | /usr/bin/wc -l` != "X1" ]; then
	    echo "Cannot identify CDR format in $conf" >&2
	    exit 1
	fi
	if [ ! -r "$cdr" ]; then
	    echo "Missing or inaccessible: $cdr" >&2
	    exit 2
	fi
	lines=`echo "X$3" | /usr/bin/sed -n 's/^X\([1-9][0-9]*\)$/\1/p'`
	if [ -z "$lines" ]; then
	    lines=50
	else
	    if [ 0 -ge "$lines" -o 10000 -lt "$lines" ]; then
		lines=50
	    fi
	fi
	echo "$fmt"
	/usr/bin/tail -n "$lines" "$cdr"
	;;
    Xnode_restart|Xnode_reload)
	cmd="${1#*_}"
	if [ -f "/usr/lib/systemd/system/$serv.service" ]; then
	    /usr/bin/systemctl "$cmd" "$serv.service" >&2 && echo "OK"
	else
	    if [ -x "/etc/rc.d/init.d/$serv" ]; then
		/sbin/service "$serv" "$cmd" >&2 && echo "OK"
	    else
		echo "Neither systemd nor Sys V init control file found for $serv" >&2
		exit 1
	    fi
	fi
	;;
    Xnode_service|Xnode_service_quiet)
	# Root is not required for this command but it's here for validations
	if [ -f "/usr/lib/systemd/system/$serv.service" ]; then
	    /usr/bin/systemctl status "$serv.service" | /usr/bin/sed -n 's/^ *\(Loaded\|Active\)\(.*\)$/\1\2/p'
	else
	    if [ -x "/etc/rc.d/init.d/$serv" ]; then
		/sbin/service "$serv" status
	    else
		if [ "X$1" != "Xnode_service_quiet" ]; then
		    echo "Neither systemd nor Sys V init control file found for $serv" >&2
		fi
		exit 1
	    fi
	fi
	;;
    *)
	echo "Invalid command" >&2
	exit 1
esac
