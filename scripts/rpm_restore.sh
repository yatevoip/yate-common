#! /bin/bash

# Restore .rpmsave configuration files after a RPM uninstall + reinstall

if [ "X$1" = "X-t" ]; then
    cmd="true"
    shift
else
    cmd="mv"
fi

files=`LANG=C LC_MESSAGES=C rpm -qc "$@" 2>/dev/null | grep '^/'`
test -z "$files" && files="$@"

code=0
for f in $files; do
    if [ -s "$f.rpmsave" ]; then
	if [ -e "$f" ]; then
	    if [ -e "$f.rpmnew" ]; then
		cmp -s "$f.rpmnew" "$f" &>/dev/null
		if [ "X$?" != "X0" ]; then
		    echo "Error: a changed .rpmnew exists, keeping $f.rpmsave" >&2
		    test "X$code" = "X0" && code=17
		    continue
		fi
	    fi
	    $cmd -f "$f" "$f.rpmnew"
	    e="$?"
	    if [ "X$e" != "X0" ]; then
		echo "Error: $e backing up $f" >&2
		code="$e"
	    fi
	else
	    test "X$cmd" = "Xtrue" && echo "Warning: missing $f" >&2
	fi
	$cmd "$f.rpmsave" "$f"
	e="$?"
	if [ "X$e" = "X0" ]; then
	    echo "Restored: $f"
	else
	    echo "Error: $e renaming $f.rpmsave" >&2
	    code="$e"
	fi
    fi
done
exit "$code"
