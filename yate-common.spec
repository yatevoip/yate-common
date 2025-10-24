# yate-common.spec
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

%define Suggests() %(LANG=C LC_MESSAGES=C rpm --help | fgrep -q ' --suggests ' && echo "Suggests:" || echo "##")
%define Recommends() %(LANG=C LC_MESSAGES=C rpm --help | fgrep -q ' --recommends ' && echo "Recommends:" || echo "##")
%{!?dist:%define dist %{?distsuffix:%distsuffix%{?product_version}}}
%define systemd %(test -x /usr/bin/systemctl && echo 1 || echo 0)
%{!?_unitdir:%define _unitdir /usr/lib/systemd/system}
%{!?tarname:%define tarname %{name}-%{version}-%{buildnum}}
# ostype will help better differentiate OS-es - we have Mageia (7 and 8) and CentOS (7 and 9)
%define ostype %(awk '{for(i=1;i<=NF;i++)if($i=="release")print $1,substr($(i+1),1,1)}' /etc/redhat-release)

%define buildnum 1

Summary:	Common files for Yate based products
Name:		yate-common
Version:	1.10
Release:	%{buildnum}%{?revision}%{?dist}
License:	GPL
Vendor:		Null Team Impex SRL
Packager:	Paul Chitescu <paulc@null.ro>
Source:		%{tarname}.tar.gz
Group:		Applications/Communication
BuildArch:	noarch
BuildRoot:	%{_tmppath}/%{tarname}-root
Provides:	yate-http_simple
%if "%{systemd}" != "0"
Requires:	/usr/bin/systemctl
%else
Requires:	/sbin/chkconfig
Requires:	/sbin/service
%endif
Requires:	/bin/bash
Requires:	/bin/tar
Requires:	/bin/grep
Requires:	/usr/bin/tail
Requires:	wget
Requires:	sudo
Requires:	net-tools
Requires:	webserver
%if "%{?rhel}%{?fedora}" != ""
%if "%{ostype}" == "CentOS 7"
Requires:	mod_php
%else
# On CentOS >=8 - mod_php has been replced by php-fpm
Requires:	php-fpm
%endif
%else
Requires:	apache-mod_php
%endif
Requires:	php-cli
Requires:	php-json
Requires:	php-sockets
Requires:	php-sysvsem
%{Recommends}	php-yaml
%{Recommends}	vlan-utils
%{Recommends}	netkit-telnet
%{Suggests}	yate-scripts
%{Suggests}	yate-database


%description
This package provides common scripting and access libraries shared by all Yate
based products.


%files
%defattr(440,root,root)
%{_sysconfdir}/sudoers.d/%{name}
%defattr(-,root,root)
%dir %{_datadir}/yate/api
%{_datadir}/yate/scripts/*
%{_datadir}/yate/api/*
%exclude %{_datadir}/yate/scripts/node_starts.php
/var/www/html/api.php
/var/www/html/api_library.php
/var/www/html/api_network.php
/var/www/html/api_version.php
/var/www/html/api_logs.php
/var/www/html/api_asroot.sh
%config(noreplace) /var/www/html/api_config.php


%post
# add protection for other product installation that might restart 
# services that we need running
count=0
while [ -f "%{_tmppath}/yate-installing" ]
do
    if [ "x$count" = "x0" ]; then
       prod=`cat "%{_tmppath}/yate-installing" 2>/dev/null`
       echo "Waiting to run postinstall for %{name} after $prod finishes installing."
    fi
    count=$((count+1))
    sleep 1
done
echo "%{name}" > "%{_tmppath}/yate-installing" 2>/dev/null

mkdir -p /var/log/json_api
chown -R apache.apache /var/log/json_api

if grep -q '^ *; *date\.timezone *=' %{_sysconfdir}/php.ini 2>/dev/null; then
    tz=`sed -n 's/^ *ZONE *= *//p' %{_sysconfdir}/sysconfig/clock 2>/dev/null`
    if [ -z "$tz" ]; then
	tz=`LANG=C LC_MESSAGES=C timedatectl 2>/dev/null | sed -n 's/.*Time zone: *\([^ ]\+\).*$/\1/p'`
    fi
    if [ -n "$tz" ]; then
	sed -i 's,^ *; *date\.timezone *=.*$,'"date.timezone = $tz," %{_sysconfdir}/php.ini
	echo "Patched %{_sysconfdir}/php.ini for timezone $tz"
    fi
fi

if [ "X$1" = "X1" ]; then
    %{_datadir}/yate/scripts/rpm_restore.sh %{name}
%if "%{systemd}" != "0"
    /usr/bin/systemctl enable httpd.service
    /usr/bin/systemctl restart httpd.service
%else
    /sbin/chkconfig httpd on
    /sbin/service httpd restart
%endif
fi
rm -f %{_tmppath}/yate-installing 2>/dev/null


%package start
Summary:	Notifies Yate cluster management about machine start
Group:		Applications/Communication
Requires:	%{name} = %{version}-%{release}
Requires:	php-curl

%description start
This package notifies the Yate cluster management when a machine providing Yate
services has been (re)started.

%files start
%{_datadir}/yate/scripts/node_starts.php
%if "%{systemd}" != "0"
%{_unitdir}/%{name}.service
%else
%{_initrddir}/%{name}
%endif

%post start
%if "%{systemd}" != "0"
    /usr/bin/systemctl daemon-reload
    /usr/bin/systemctl restart %{name}.service
%else
    /sbin/service %{name} restart
%endif
if [ "X$1" = "X1" ]; then
%if "%{systemd}" != "0"
    /usr/bin/systemctl enable %{name}.service
%else
    /sbin/chkconfig %{name} on
%endif
fi

%prep
%setup -q -n %{name}

# older rpmbuild uses these macro basic regexps
%define _requires_exceptions pear
# newer rpmbuild needs these global extended regexps
%global __requires_exclude pear

%define local_find_requires %{_builddir}/yate-common/local-find-requires
%{__cat} <<EOF >%{local_find_requires}
#! /bin/sh
%{__find_requires} | grep -v 'libyate\|yateversn\|php-cli'
exit 0
EOF
chmod +x %{local_find_requires}
%define _use_internal_dependency_generator 0
%define __find_requires %{local_find_requires}


%build


%install
%if "%{systemd}" != "0"
mkdir -p %{buildroot}%{_unitdir}
cp -p %{name}.service %{buildroot}%{_unitdir}/
%else
mkdir -p %{buildroot}%{_initrddir}
cp -p %{name}.init %{buildroot}%{_initrddir}/%{name}-start
%endif
mkdir -p %{buildroot}%{_sysconfdir}/sudoers.d
mkdir -p %{buildroot}%{_datadir}/yate/api
mkdir -p %{buildroot}%{_datadir}/yate/scripts
mkdir -p %{buildroot}/var/www/html
cp -p %{name}.sudo %{buildroot}%{_sysconfdir}/sudoers.d/%{name}
cp -p scripts/* %{buildroot}%{_datadir}/yate/scripts/
cp -p api/* %{buildroot}/var/www/html/
cp -p doc/*.json %{buildroot}%{_datadir}/yate/api/
echo '<?php $api_version = "%{version}-%{release}"; ?>' > %{buildroot}/var/www/html/api_version.php


%clean
rm -rf %{buildroot}


%changelog
* Sat Aug 22 2020 Paul Chitescu <paulc@null.ro>
- Added "start" subpackage

* Wed Apr 30 2014 Paul Chitescu <paulc@null.ro>
- Created specfile
