# override DESTDIR at install time to prefix the install directory
DESTDIR :=

PKGNAME := yate-common
VERSION := $(shell sed -n 's/^Version:[[:space:]]*//p' $(PKGNAME).spec)
RELEASE := $(shell sed -n 's/^%define[[:space:]]\+buildnum[[:space:]]\+//p' $(PKGNAME).spec)
TARNAME := $(PKGNAME)-$(VERSION)-$(RELEASE)
SRPMDIR := $(HOME)/rpmbuild/SRPMS
GITTAG  := $(shell LANG=C LC_MESSAGES=C git tag 2>/dev/null | tail -n 1)
GIT_HASH := $(shell LANG=C LC_MESSAGES=C git rev-list -n1 HEAD 2>tmp.txt)
GIT_ERR := $(shell LANG=C LC_MESSAGES=C cat tmp.txt 2>/dev/null; rm -f tmp.txt 2>/dev/null )
SUFFIX  :=
RPMOPT  :=

.PHONY: all clean install uninstall rpm srpm rpm-head srpm-head srpm-git check-gittag build-git build-srpm tarball

all:

# include optional local make rules
-include Makefile.local

rpm: check-gittag tarball
	rpmbuild -tb --define 'tarname $(TARNAME)' --define 'revision $(if $(SUFFIX),$(SUFFIX),%{nil})' $(RPMOPT) tarballs/$(TARNAME).tar.gz

srpm: check-gittag tarball
	rpmbuild -ta --define 'tarname $(TARNAME)' --define 'revision $(if $(SUFFIX),$(SUFFIX),%{nil})' $(RPMOPT) tarballs/$(TARNAME).tar.gz

# build packages from GIT HEAD
rpm-head: check-githash tarball
	rpmbuild -tb --define 'tarname $(TARNAME)' --define 'revision _$(GIT_HASH)git' $(RPMOPT) tarballs/$(TARNAME).tar.gz

srpm-head: check-githash tarball
	rpmbuild -ta --define 'tarname $(TARNAME)' --define 'revision _$(GIT_HASH)git' $(RPMOPT) tarballs/$(TARNAME).tar.gz

# build packages with tagged version
srpm-git: check-gittag tarball
	rpmbuild -ta --define 'tarname $(TARNAME)' --define 'revision _t$(GITTAG)$(SUFFIX)' $(RPMOPT) tarballs/$(TARNAME).tar.gz

check-gittag: check-githash
	@tag_hash=""; \
	if [ "" != "$(GITTAG)" ]; then \
	    tag_hash=`LANG=C LC_MESSAGES=C git rev-list -n1 $(GITTAG) 2>/dev/null`; \
	else \
	    echo "No available GIT tag"; \
	    exit 1; \
	fi; \
	if [ "x$(GIT_HASH)" != "x$$tag_hash" ]; then \
	    echo "Current commit hash $(GIT_HASH) different from expected hash for tag $(GITTAG) ($$tag_hash)"; \
	    exit 1; \
	fi;

check-githash:
	@if [ "x" = "x$(GIT_HASH)" ]; then \
	    echo "Could not obtain last GIT commit hash. GIT Error:"; \
	    echo "$(GIT_ERR)"; \
	    exit 1; \
	fi;

build-git: check-gittag tarball
	@for f in "$(SRPMDIR)/$(TARNAME)_t$(GITTAG)$(SUFFIX)."*.src.rpm ; do \
	    if [ -s "$$f" ]; then \
		echo "Already having $$f"; \
		exit; \
	    fi \
	done ; \
	$(MAKE) srpm-git

build-srpm:
	@for f in "$(SRPMDIR)/$(TARNAME)$(SUFFIX)."*.src.rpm ; do \
	    if [ -s "$$f" ]; then \
		echo "Already having $$f"; \
		exit; \
	    fi \
	done ; \
	$(MAKE) srpm

tarball: clean
	@wd=`pwd|sed 's,^.*/,,'`; \
	mkdir -p tarballs; cd ..; \
	find $$wd -name .svn >>$$wd/tarballs/tar-exclude; \
	find $$wd -name .git >>$$wd/tarballs/tar-exclude; \
	find $$wd -name '.gitignore' >>$$wd/tarballs/tar-exclude; \
	find $$wd -name '*~' >>$$wd/tarballs/tar-exclude; \
	tar czf $$wd/tarballs/$(TARNAME).tar.gz --exclude $$wd/Makefile.local --exclude $$wd/tarballs -X $$wd/tarballs/tar-exclude $$wd; \
	rm $$wd/tarballs/tar-exclude
