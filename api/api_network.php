<?php

/* api_network.php
 * This file is part of the YATE Project http://YATE.null.ro
 *
 * JSON over HTTP network address API for Yate products
 *
 * Yet Another Telephony Engine - a fully featured software PBX and IVR
 * Copyright (C) 2014-2023 Null Team
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

function getNetAddress($filtered = true)
{
    $output = array();
    $return = -1;
    exec("/sbin/ifconfig",$output,$return);
    if ($return || !count($output))
	return buildError(501,"Cannot retrieve network interfaces");
    $list = array();
    $ifc = null;
    $alias = null;
    for ($i = 0; $i < count($output); $i++) {
	$line = $output[$i];
	if ("" == $line) {
	    $ifc = null;
	    continue;
	}
	$matches = array();
	if (preg_match('/^([^: ]+)[: ]/',$line,$matches)) {
	    $ifc = $matches[1];
	    $alias = null;
	    if ($filtered && preg_match('/^(lo|tun-)/',$ifc)) {
		$ifc = null;
		continue;
	    }
	    if (!isset($list[$ifc]))
		$list[$ifc] = array("interface" => $ifc);
	    if (preg_match('/ HWaddr *([[:xdigit:]:]+) *$/',$line,$matches))
		$list[$ifc]["ethernet"] = $matches[1];
	    if (preg_match('/ mtu *([0-9]+) ?/',$line,$matches))
		$list[$ifc]["mtu"] = intval($matches[1],10);
	    if (preg_match('/^[^:. ]+\.([0-9]+)[: ]/',$line,$matches))
		$list[$ifc]["vlan"] = intval($matches[1],10);
	    if (preg_match('/^[^: ]+:([^: ]+)[: ]/',$line,$matches))
		$alias = $matches[1];
	    continue;
	}
	if (null === $ifc)
	    continue;
	if (preg_match('/^ +ether +([[:xdigit:]:]+) /',$line,$matches))
	    $list[$ifc]["ethernet"] = $matches[1];
	else if (preg_match('/ MTU: *([0-9]+) ?/',$line,$matches))
	    $list[$ifc]["mtu"] = intval($matches[1],10);
	else if (preg_match('/^ +inet( addr:)? *([0-9.]+) .*(Mask:|netmask) *([0-9.]+) ?/',$line,$matches)) {
	    if (!isset($list[$ifc]["ipv4"]))
		$list[$ifc]["ipv4"] = array();
	    $ip4 = array("address" => $matches[2], "netmask" => $matches[4]);
	    if (null !== $alias) {
		$ip4["alias"] = $alias;
		$alias = null;
	    }
	    $list[$ifc]["ipv4"][] = $ip4;
	}
	else if (preg_match('/^ +inet6 addr: *([[:xdigit:]:]+)\/([0-9]+) ?/',$line,$matches)) {
	    if (!isset($list[$ifc]["ipv6"]))
		$list[$ifc]["ipv6"] = array();
	    $list[$ifc]["ipv6"][] = array("address" => $matches[1], "prefixlen" => intval($matches[2],10));
	}
	else if (preg_match('/^ +inet6 +([[:xdigit:]:]+) .* prefixlen +([0-9]+) ?/',$line,$matches)) {
	    if (!isset($list[$ifc]["ipv6"]))
		$list[$ifc]["ipv6"] = array();
	    $list[$ifc]["ipv6"][] = array("address" => $matches[1], "prefixlen" => intval($matches[2],10));
	}
    }
    $output = array();
    foreach ($list as $ifc)
	$output[] = $ifc;
    return buildSuccess("net_address",$output);
}

/* vi: set ts=8 sw=4 sts=4 noet: */
?>
