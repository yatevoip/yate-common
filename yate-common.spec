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

%define buildnum 1

Summary:	Common files for Yate based products
Name:		yate-common
Version:	1.5
Release:	%{buildnum}%{?revision}%{?dist}
License:	GPL
Vendor:		Null Team Impex SRL
Packager:	Paul Chitescu <paulc@null.ro>
Source:		%{tarname}.tar.gz
Group:		Applications/Communication
BuildArch:	noarch
BuildRoot:	%{_tmppath}/%{tarname}-root
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
Requires:	mod_php
%else
Requires:	apache-mod_php
%endif
Requires:	php-cli
Requires:	php-json
Requires:	php-sockets
Requires:	php-sysvsem
Requires:	yate-scripts
%{Recommends}	vlan-utils
%{Recommends}	netkit-telnet
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
/var/www/html/api.php
/var/www/html/api_network.php
/var/www/html/api_version.php
/var/www/html/api_logs.php
/var/www/html/api_asroot.sh
%config(noreplace) /var/www/html/api_config.php


%post
mkdir -p /var/log/json_api
chown -R apache.apache /var/log/json_api

if grep -q '^ *; *date\.timezone *=' %{_sysconfdir}/php.ini 2>/dev/null; then
    tz=`sed -n 's/^ *ZONE *= *//p' %{_sysconfdir}/sysconfig/clock`
    if [ -n "$tz" ]; then
	sed -i 's,^ *; *date\.timezone *=.*$,'"date.timezone = $tz," %{_sysconfdir}/php.ini
	echo "Patched %{_sysconfdir}/php.ini for timezone $tz"
    fi
fi

if [ "X$1" = "X1" ]; then
%if "%{systemd}" != "0"
    /usr/bin/systemctl enable httpd.service
    /usr/bin/systemctl restart httpd.service
%else
    /sbin/chkconfig httpd on
    /sbin/service httpd restart
%endif
fi


%prep
%setup -q -n %{name}

# older rpmbuild uses these macro basic regexps
%define _requires_exceptions pear
# newer rpmbuild needs these global extended regexps
%global __requires_exclude pear

%define local_find_requires %{_builddir}/%{name}/local-find-requires
%{__cat} <<EOF >%{local_find_requires}
#! /bin/sh
%{__find_requires} | grep -v 'libyate\.php'
exit 0
EOF
chmod +x %{local_find_requires}
%define _use_internal_dependency_generator 0
%define __find_requires %{local_find_requires}


%build


%install
mkdir -p %{buildroot}%{_sysconfdir}/sudoers.d
mkdir -p %{buildroot}%{_datadir}/yate/api
mkdir -p %{buildroot}%{_datadir}/yate/scripts
mkdir -p %{buildroot}/var/www/html
cp -p %{name}.sudo %{buildroot}%{_sysconfdir}/sudoers.d/%{name}
cp -p scripts/* %{buildroot}%{_datadir}/yate/scripts/
cp -p api/* %{buildroot}/var/www/html/
echo '<?php $api_version = "%{version}-%{release}"; ?>' > %{buildroot}/var/www/html/api_version.php


%clean
rm -rf %{buildroot}


%changelog
* Wed Apr 30 2014 Paul Chitescu <paulc@null.ro>
- Created specfile
