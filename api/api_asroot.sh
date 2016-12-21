#! /bin/sh

# api_asroot.sh
# This file is part of the YATE Project http://YATE.null.ro
#
# Yet Another Telephony Engine - a fully featured software PBX and IVR
# Copyright (C) 2015-2016 Null Team
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

if (echo "$2" | /bin/grep -q -v '^[[:alnum:]_.-]\+$') 2>/dev/null; then
    echo "Invalid node name" >&2
    exit 1
fi

case "X$1" in
    Xget_node_config)
	dir="/etc/yate/$2"
	if [ -d "$dir" ]; then
	    cd "$dir"
	    /usr/bin/tar -czf - *.conf
	else
	    echo "Not a directory: $dir" >&2
	    exit 20
	fi
	;;
    Xget_node_logs)
	log="/var/log/yate-$2"
	if [ -f "$log" ]; then
	    /usr/bin/tail -c 500000 "$log"
	else
	    echo "Not a file: $log" >&2
	    exit 2
	fi
	;;
    Xnode_restart)
	if [ -f "/usr/lib/systemd/system/yate-$2.service" ]; then
	    /usr/bin/systemctl restart "yate-$2.service" >&2 && echo "OK"
	else
	    if [ -x "/etc/rc.d/init.d/yate-$2" ]; then
		/sbin/service "yate-$2" restart >&2 && echo "OK"
	    else
		echo "Neither systemd nor Sys V init control file found" >&2
		exit 1
	    fi
	fi
	;;
    *)
	echo "Invalid command" >&2
	exit 1
esac
