<?php # vim:ts=2:sw=2:et:
/* Copyright (c) 2010 Message Systems, Inc.
 * Author: Wez Furlong
 * License: Modified BSD, See LICENSE file.
 */

class Branch {
  public $name;
  public $createdrev;
  public $activity = array();
  public $origin;
  public $include = true;
  public $is_tag = false;
  public $is_pure = true;
  public $bname;
  /* record individual node paths modified against this branch so that
   * we can later on figure out how to get at the content */
  public $activity_by_path = array();

  function __construct($rev, $path) {
    $this->createdrev = $rev;
    $this->name = $path;
    if (strpos($this->name, '/tags/') || !strncmp($this->name, 'tags/', 5)) {
      $this->is_tag = true;
    }
    $this->bname = preg_replace("/^(tags|branches)\//", '', $this->name);
    global $mainline_branch;
    if ($this->bname == $mainline_branch) {
      $this->bname = 'master';
    }
  }

  function fixpath($str) {
    $res = substr($str, strlen($this->name) + 1);

    if (strncmp($str, $this->name, strlen($this->name))) {
      throw new Exception("fixpath: $str does not fall within $this->name");
    }
    return $res;
  }

  function addActivity(SvnDumpReader $R) {
    $this->activity[$R->revision] = $R->revision;
    foreach ($R->nodes as $node) {
      if (is_child_of($node->path, $this->name)) {
        $this->activity_by_path[$node->path][$R->revision] = $R->revision;
        switch ($node->action) {
          case 'add':
          case 'change':
          case 'replace':
            $this->is_pure = false;
            break;
          case 'delete':
            if ($node->path != $this->name) {
              $this->is_pure = false;
            }
            break;
          default:
            echo "Unhandled action $node->action\n";
            exit;
        }
      }
    }
  }

  function __toString() {
    if ($this->is_tag) {
      if ($this->is_pure) {
        $tag = ' pure-tag';
      } else {
        $tag = ' impure-tag';
      }
    } else {
      $tag = '';
    }
    return "Branch($this->name -> $this->bname)$tag";
  }
}

class Repo {
  /** max number of revisions to process */
  public $limit;

  /** current generation of branches keyed by path name */
  public $branches = array();

  /** deleted branches by name; the value is an array of
   * Branch objects, with the most recently deceased at the front */
  public $dead_branches = array();

  function add_branch($name, $revision = null) {
    if ($name instanceof SvnDumpNode) {
      if ($revision === null) {
        $revision = $name->revision;
      }
      $name = $name->path;
    }
    if (isset($this->branches[$name])) {
      throw new Exception("creating branch $name, but it's already here");
    }
    $b = new Branch($revision, $name);
    $this->branches[$name] = $b;
    return $b;
  }

  function delete_branch($b, $revision = null) {
    if ($b instanceof SvnDumpNode) {
      if ($revision === null) {
        $revision = $b->revision;
      }
      $b = $this->find_branch($b);
    } elseif (is_string($b)) {
      $b = $this->find_branch($b);
    }
    if (!($b instanceof Branch)) {
      throw new Exception("I don't know what branch $b is");
    }
    if (!isset($this->branches[$b->name]) ||
        $this->branches[$b->name] !== $b) {
      throw new Exception("this branch isn't alive! $b");
    }
    unset($this->branches[$b->name]);
    if ($b->deleted === null) {
      if ($revision === null) {
        throw new Exception("I don't know the deletion revision");
      }
      $b->deleted = $revision;
    }
    $b->bname = $b->bname . '@' . $b->createdrev . '-' . $b->deleted;
    if (!isset($this->dead_branches[$b->name])) {
      $this->dead_branches[$b->name] = array($b);
    } else {
      array_unshift($this->dead_branches[$b->name], $b);
    }
  }

  function find_branch($name, $revision = null)
  {
    if ($name instanceof SvnDumpNode) {
      $revision = $name->revision;
      $name = $name->path;
    }
#    echo "find_branch($name, $revision)\n";

    $path = $name;
    while (strlen($path)) {
      if (isset($this->branches[$path])) {
        $b = $this->branches[$path];

        if ($revision === null || $revision >= $b->createdrev) {
          return $b;
        }
        /* this is not the branch we are looking for */
      }
      $path = dirname($path);
      if ($path == '.') {
        break;
      }
    }
#    echo "  checking dead_branches\n";

    /* didn't find it in the current branches, go look in the
     * dead branches */
    $path = $name;
    while (strlen($path)) {
      if (isset($this->dead_branches[$path])) {
#        echo "  dead: $path\n";
        $dead = $this->dead_branches[$path];
        foreach ($dead as $b) {
#          echo "    D: $b\n";
          if ($revision === null ||
              ($revision >= $b->createdrev && $revision < $b->deleted)) {
            return $b;
          }
        }
      }
      $path = dirname($path);
      if ($path == '.') {
        break;
      }
    }
    /* no such branch */
    return null;
  }
}

