#! /bin/bash

# Prepare a system SCTP configuration suitable for SIGTRAN and Diameter
# Particularly M2PA is very sensitive to retransmission timings

if ! grep -q -i 'sctp' /etc/modules 2>/dev/null; then
    cat <<-EOF >>/etc/modules
	# load SCTP early so sysctl can alter parameters at boot
	sctp
	EOF
    modprobe sctp
fi
if ! grep -q -i 'sctp' /etc/sysctl.conf 2>/dev/null; then
    cat <<-EOF >>/etc/sysctl.conf
	# SCTP parameters more suitable for telephony
	net.sctp.rto_min = 200
	net.sctp.rto_max = 400
	net.sctp.rto_initial = 200
	net.sctp.hb_interval = 20000
	net.sctp.path_max_retrans = 2
	EOF
    sysctl -p
fi

if test -d /etc/sysctl.d -a ! -e /etc/sysctl.d/99-sysctl.conf;then
	ln -s /etc/sysctl.conf /etc/sysctl.d/99-sysctl.conf
fi
