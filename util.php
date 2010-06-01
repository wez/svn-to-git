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


