# override DESTDIR at install time to prefix the install directory
DESTDIR :=

PKGNAME := yate-common
VERSION := $(shell sed -n 's/^Version:[[:space:]]*//p' $(PKGNAME).spec)
RELEASE := $(shell sed -n 's/^%define[[:space:]]\+buildnum[[:space:]]\+//p' $(PKGNAME).spec)
TARNAME := $(PKGNAME)-$(VERSION)-$(RELEASE)
SRPMDIR := $(HOME)/rpmbuild/SRPMS
SVNREV  := $(shell LANG=C LC_MESSAGES=C svn info 2>/dev/null | sed -n 's,^Last Changed Rev: *,,p')
SUFFIX  :=
RPMOPT  :=

.PHONY: all clean install uninstall rpm srpm srpm-svn check-svn tarball

all:

# include optional local make rules
-include Makefile.local

rpm: tarball
	rpmbuild -tb --define 'tarname $(TARNAME)' --define 'revision $(if $(SUFFIX),$(SUFFIX),%{nil})' $(RPMOPT) tarballs/$(TARNAME).tar.gz

srpm: tarball
	rpmbuild -ta --define 'tarname $(TARNAME)' --define 'revision $(if $(SUFFIX),$(SUFFIX),%{nil})' $(RPMOPT) tarballs/$(TARNAME).tar.gz

srpm-svn: check-svn tarball
	rpmbuild -ta --define 'tarname $(TARNAME)' --define 'revision _r$(SVNREV)$(SUFFIX)' $(RPMOPT) tarballs/$(TARNAME).tar.gz

check-svn:
	@if [ -z "$(SVNREV)" ]; then echo "Cannot determine SVN revision" >&2; false; fi

build-svn: check-svn
	@for f in "$(SRPMDIR)/$(TARNAME)_r$(SVNREV)$(SUFFIX)."*.src.rpm ; do \
	    if [ -s "$$f" ]; then \
		echo "Alredy having $$f"; \
		exit; \
	    fi \
	done ; \
	$(MAKE) srpm-svn

build-srpm:
	@for f in "$(SRPMDIR)/$(TARNAME)$(SUFFIX)."*.src.rpm ; do \
	    if [ -s "$$f" ]; then \
		echo "Alredy having $$f"; \
		exit; \
	    fi \
	done ; \
	$(MAKE) srpm

tarball: clean
	@wd=`pwd|sed 's,^.*/,,'`; \
	mkdir -p tarballs; cd ..; \
	find $$wd -name .svn >>$$wd/tarballs/tar-exclude; \
	tar czf $$wd/tarballs/$(TARNAME).tar.gz --exclude $$wd/Makefile.local --exclude $$wd/tarballs -X $$wd/tarballs/tar-exclude $$wd; \
	rm $$wd/tarballs/tar-exclude
