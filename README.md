svn-to-git
==========

A Subversion to Git migration tool for repositories with complex layouts.

By complex layouts, I mean:

1.  You have multi-level tags and branches like tags/2.0/2.0.1 and
    branches/2.2/2.2.1

2.  You have multiple sub-projects in your repo and have copied code
    from other areas of the repo into your project of interest.

3.  You may have, at some point, checked in code against a tag.

Installation
------------

You need:

1.  PHP 5.2 or later, preferably a 64-bit build, as 32-bit PHP cannot seek
    around in Subversion dump files larger than 2GB in size.

2.  A Subversion repository dumped via "svnadmin dump"

3.  Enough disk space; potentially up to 3 time the size of the dump file,
    depending on how much of the repo you want to pull into Git.

Operation
---------

For help, run svn-to-git --help.

You'll typically run in two stages; analysis while you figure out what you
want, and then complete the run when you're happy.

### Analysis ###

    ./svn-to-git --svn-dump my.dump --analyze

This will examine your repo and tell what it's going to do.  It may ask you
to add --branch or --exclude-branch hints and re-run.

### Completion ###

Once you're happy with the plan, you can run it to completion:

    ./svn-to-git --svn-dump my.dump --complete --git-repo /path/to/target/git

You'll almost certainly want to use the --authors option to set up an
author map.

Check out the --help output for more information.

Background Information
======================

Subversion has a somewhat strange notion of branches.  People (or perhaps just
us) abuse or extend the branches/branchname and tags/tagname convention and use
hierarchical schemes.  Sometimes people commit to tags because it is convenient
to do so.

All of the existing migration tools I have found assume that you're using
subversion 100% according to the conventions suggested (but not mandated) by
the subversion documentation.  This can lead to a broken conversion.

How svn-to-git works:

First obtain an svndump of your Subversion repository.  svn-to-git will only
process dump files; it cannot work with a repository URL.

Second, tell svn-to-git which is the mainline for your repository.  The default
is trunk, but any location in the repo is acceptable.

svn-to-git will then analyze the dump file to determine branches created from
your main line; these branches will be followed, and branches created from
those branches will be followed recursively.

If svn-to-git finds merge activity coming in to any of the tracked branches
from non-tracked branches, the analysis will bail out and ask you to re-try,
but this time with a hint on whether you want to exclude or include the
non-tracked branch.

At the end of the analysis, svn-to-git has information on all the branches that
will take part in the Git migration.

Tags:

Subversion has tags-by-convention.  They're really branches.  These are not
compatible with the immutable nature of tags in Git.  If svn-to-git detects a
tag in the svn repo, it checks the commit activity against the tag; if there is
no activity beyond the initial create (or final delete) then the tag is deemed
to be a pure tag and will be created as a annotated tag in the Git repository.
If the tag is impure (it has any other changes applied against it) then
svn-to-git will create a branch instead, and will create a tag for the final
commit against that branch.

With the analysis complete, svn-to-git generates a data file to feed into the
Git fast-import tool.

