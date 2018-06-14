#! /bin/sh

# api_asroot.sh
# This file is part of the YATE Project http://YATE.null.ro
#
# Yet Another Telephony Engine - a fully featured software PBX and IVR
# Copyright (C) 2015-2017 Null Team
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
if [ -s "$ext" -a -O "$ext" ]; then
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
		/usr/bin/tar -czf - *.conf *.json *.xml *.inc *.php *.js *.sh *.crt --ignore-failed-read 2>/dev/null
	    fi
	else
	    echo "Not a directory: $dir" >&2
	    exit 20
	fi
	;;
    Xget_node_logs)
	log="/var/log/$serv"
	if [ -f "$log" ]; then
	    /usr/bin/tail -c 500000 "$log"
	else
	    echo "Not a file: $log" >&2
	    exit 2
	fi
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
