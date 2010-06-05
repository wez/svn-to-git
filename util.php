<?php # vim:ts=2:sw=2:et:
/* Copyright (c) 2010 Message Systems, Inc.
 * Author: Wez Furlong
 * License: Modified BSD, See LICENSE file.
 */

class SvnFile {
  /* short names here are to reduce the size of the serialized blob */
  protected $m;
  protected $h;
  protected $n;
  protected $k = null;
  protected $g;
  static $sleep = array('m', 'h', 'n', 'k');
  const IS_ROOT = 0;
  const IS_FILE = 1;
  const IS_EXEC = 2;
  const IS_DIR = 3;

  function __construct($rev, $type = null) {
    if ($type !== null) {
      $this->setType($type);
    } else {
      $this->m = self::IS_ROOT;
    }
    $this->g = $rev;
  }

  function __sleep() {
    return self::$sleep;
  }

  /* store the sha1 as the binary bits internally to reduce memory
   * usage both at runtime and in the serialized blob */
  function setHash($hash) {
    $this->h = pack('H*', $hash);
  }

  function getHash() {
    return bin2hex($this->h);
  }

  function getChild($name) {
    foreach ($this->k as $k => $v) {
      if ($v->n == $name) {
        return $v;
      }
    }
    return null;
  }

  function getChildren() {
    return $this->k;
  }

  function setChild(SvnFile $kid) {
    if (is_array($this->k)) {
      foreach ($this->k as $k => $v) {
        if ($v->n == $kid->n) {
          $this->k[$k] = $kid;
          return;
        }
      }
    }
    $this->k[] = $kid;
  }

  function removeChild($name) {
    foreach ($this->k as $k => $v) {
      if ($v->n == $name) {
        unset($this->k[$k]);
        return true;
      }
    }
    return false;
  }

  function getType() {
    if ($this->m == self::IS_DIR) return 'dir';
    if ($this->m == self::IS_ROOT) return 'root';
    if ($this->m == self::IS_EXEC) return 'file';
    if ($this->m == self::IS_FILE) return 'file';
    throw new Exception("Invalid mode $this->m on $this->n");
  }

  function getMode() {
    if ($this->m == self::IS_EXEC) return '100755';
    return '100644';
  }

  function setType($type, $isexe = false) {
    if ($type == 'file') {
      $this->m = $isexe ? self::IS_EXEC : self::IS_FILE;
    } else if ($type == 'dir') {
      $this->m = self::IS_DIR;
    } else {
      throw new Exception("Invalid type $type on $this->n");
    }
  }

  function setName($name) {
    $this->n = $name;
  }

  function getName() {
    return $this->n;
  }

  function __clone() {
    /* explicitly want a shallow copy */
  }

  function cloneRoot($rev) {
    if ($this->m != self::IS_ROOT) {
      throw new Exception("node is not a root");
    }
    $c = clone $this;
    $c->g = $rev;
    return $c;
  }

  function resolve($path) {
    if ($this->m != self::IS_ROOT) {
      throw new Exception("call me from the root of the tree");
    }
    if ($path == '.' || strlen($path) == 0) {
      return $this;
    }
    $bits = explode('/', $path);
    $node = $this;
    while (count($bits)) {
      $p = array_shift($bits);
      $k = $node->getChild($p);
      if ($k === null) {
        throw new Exception("$path not found in $node->n");
      }
      $node = $k;
    }
    return $node;
  }

  function resolveForWrite($path) {
    if ($this->m != self::IS_ROOT) {
      throw new Exception("call me from the root of the tree");
    }
    if ($path == '.' || strlen($path) == 0) {
      return $this;
    }
    $bits = explode('/', $path);
    $node = $this;
    $parent = null;
    while (count($bits)) {
      $p = array_shift($bits);
      $k = $node->getChild($p);
      if ($k === null) {
        var_dump($node);
        throw new Exception("$path not found in $node->n");
      }
      if ($k->g != $this->g) {
        $k = clone $k;
        $k->g = $this->g;
        $node->setChild($k);
      }
      $node = $k;
    }
    if ($node->g != $this->g) {
      throw new Exception("somehow ended up with the wrong generation!");
    }
    if ($node->g == 0) {
      throw new Exception("how am I zero?");
    }
    return $node;
  }

  /* returns a hash of FQN => SvnFile for each item under the specified $path */
  function getFQList($path) {
    $list = array();
    $n = $this->resolve($path);
    $n->getFQListHelper($path, $list);
    return $list;
  }

  private function getFQListHelper($path, &$list)
  {
    /* $path corresponds to $this */
    $list[$path] = $this;
    if (is_array($this->k)) {
      foreach ($this->k as $k) {
        $n = $k->getName();
        $k->getFQListHelper("$path/$n", $list);
      }
    }
  }

  function print_listing($relpath = '') {
    if ($this->getType() == 'dir') {
      if (strlen($relpath)) $relpath .= "/";
      echo "$relpath$this->n:\n";
    } else {
      echo "TOP:\n";
    }
    foreach ($this->k as $k) {
      if ($k->getType() == 'dir') {
        echo "   $k->n/\n";
      } else {
        echo "   $k->n\n";
      }
    }
    foreach ($this->k as $k) {
      if ($k->getType() == 'dir') {
        $k->print_listing("$relpath$this->name");
      }
    }
  }
}

class DataLocator {
  public $size;
  public $offset;
}


/* Given an array with numeric keys, locate the
 * requested element, or the one with the next smallest
 * key */
function find_key_or_prior2($arr, $wanted, &$actual)
{
  if (isset($arr[$wanted])) {
    $actual = $wanted;
    return $k;
  }
  /* would be nice to assume something about ordering, but cannot */
//  arsort($arr);
  foreach ($arr as $k => $v) {
    if ($k < $wanted) {
      $actual = $k;
      return $v;
    }
  }
  $actual = null;
  return null;
}

function find_key_or_prior($arr, $wanted)
{
  $actual = 0;
  return find_key_or_prior2($arr, $wanted, $actual);
}

class Branch {
  public $name;
  public $createdrev;
  public $activity = array();
  public $origin;
  public $include = true;
  public $is_tag = false;
  public $is_pure = true;
  public $bname;

  /* record props by path by revision.
   * [$propname][$path][$revision] = $value */
  public $props = array();

  function __construct($rev, $path) {
    $this->createdrev = $rev;
    $this->name = $path;
    if (strpos($this->name, '/tags/') || !strncmp($this->name, 'tags/', 5)) {
      $this->is_tag = true;
    }
    global $mainline_branch;
    global $branch_rewrite_rules;

    if ($this->name == $mainline_branch) {
      $this->bname = 'master';
    } else {
      foreach ($branch_rewrite_rules as $search => $replace) {
        $res = preg_replace($search, $replace, $this->name);
        if ($this->name !== $res) {
          $this->bname = $res;
          break;
        }
      }

      if ($this->bname === null) {
        $this->bname = preg_replace("/^(tags|branches)\//", '', $this->name);
      }
    }
  }

  function fixpath($str) {
    $res = substr($str, strlen($this->name) + 1);

    if (strncmp($str, $this->name, strlen($this->name))) {
      throw new Exception("fixpath: $str does not fall within $this->name");
    }
    return $res;
  }

  function getPropsForRev($rev, $wantedprop) {
    $props = array();
    if (isset($this->props[$wantedprop])) {
      foreach ($this->props[$wantedprop] as $path => $plist) {
        $val = find_key_or_prior2($plist, $rev, $r);
        if ($val !== null) {
          $props[$path] = $val;
          if ($r < $rev) {
            // carry forward to improve performance of next lookup
            $this->props[$wantedprop][$path][$rev] = $val;
          }
        }
      }
    }
    return $props;
  }

  function addActivity(SvnDumpReader $R, Repo $S) {
    $this->activity[$R->revision] = $R->revision;
    foreach ($R->nodes as $node) {
      if (is_child_of($node->path, $this->name)) {
        if (is_array($node->props)) {
          foreach ($node->props as $propname => $propval) {
            $this->props[$propname][$node->path][$R->revision] = $propval;
            arsort($this->props[$propname][$node->path]);
          }
        }
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

  /** given a sha1 of a blob, find offset and size within dump file */
  public $blob_by_sha1 = array();

  private $tree_by_rev = array();

  function get_tree_by_rev($rev)
  {
    return $this->tree_by_rev[$rev];
  }

  function get_blob_by_sha1($sha1) {
    return $this->blob_by_sha1[$sha1];
  }

  function discover(SvnDumpReader $R) {
    if ($R->revision) {
      $tree = $this->get_tree_by_rev($R->revision - 1);
      if ($tree === null) {
        throw new Exception("no tree for " . ($R->revision - 1));
      }
      $tree = $tree->cloneRoot($R->revision);
    } else {
      $tree = new SvnFile($R->revision);
    }
    foreach ($R->nodes as $node) {
      if ($node->sha1 !== null && !isset($this->blob_by_sha1[$node->sha1])) {
        $d = new DataLocator;
        $d->size = $node->size;
        $d->offset = $node->start;
        $this->blob_by_sha1[$node->sha1] = $d;
      }
      switch ($node->action) {
        case 'add':
          $pdir = dirname($node->path);
          $pnode = $tree->resolveForWrite($pdir);

          if ($node->kind == 'dir' &&
              isset($node->meta['node-copyfrom-path'])) {
            $stree = $this->get_tree_by_rev($node->meta['node-copyfrom-rev']);
            $n = $stree->resolve($node->meta['node-copyfrom-path']);
            $n = clone $n;
          } else {
            $n = new SvnFile($R->revision);
            if ($node->kind == 'dir') {
              $n->setType('dir');
            } else {
              $n->setType('file', isset($node->props['svn:executable']));
              $n->setHash($this->determine_sha1_for_node($node));
            }
          }
          $n->setName(basename($node->path));
          $pnode->setChild($n);
          break;

        case 'delete':
          $pdir = dirname($node->path);
          $pnode = $tree->resolveForWrite($pdir);
          $pnode->removeChild(basename($node->path));
          break;

        case 'change':
          $n = $tree->resolveForWrite($node->path);
          if ($node->kind == 'file') {
            if ($node->sha1 !== null) {
              $n->setHash($node->sha1);
            }
            if (is_array($node->props)) {
              $n->setType('file', isset($node->props['svn:executable']));
            }
          } else {
            /* can trigger when props change on a dir */
          }
          break;

        case 'replace':
          if ($node->kind == 'dir') {
            $pdir = dirname($node->path);
            $pnode = $tree->resolve($pdir);
            $pnode->removeChild(basename($node->path));
            $n = new SvnFile($R->revision, 'dir');
            $n->setName(basename($node->path));
            $pnode->setChild($n);
          } else {
            $n = $tree->resolveForWrite($node->path);
            if ($node->sha1 !== null) {
              $n->setHash($node->sha1);
            }
            if (is_array($node->props)) {
              $n->setType('file', isset($node->props['svn:executable']));
            }
          }
          break;

        default:
          var_dump($node);
          throw new Exception(
            "unhandled action $node->action $node->kind $node->path");
      }
    }
    $this->tree_by_rev[$R->revision] = $tree;
  }

  function determine_sha1_for_node(SvnDumpNode $node) {
    if ($node->kind == 'dir') {
      return null;
    }
    if ($node->kind != 'file') {
      throw new Exception("$node->kind nodes don't have a sha1");
    }
    if ($node->sha1 !== null) return $node->sha1;
    if (isset($node->meta['text-copy-source-sha1'])) {
      return $node->meta['text-copy-source-sha1'];
    }
    if (isset($node->meta['node-copyfrom-rev']) &&
        isset($node->meta['node-copyfrom-path'])) {
      $cpath = $node->meta['node-copyfrom-path'];
      $crev = $node->meta['node-copyfrom-rev'];
      $t = $this->tree_by_rev[$crev];
      $n = $t->resolve($cpath);
      return $n->getHash();
    }
    return null;
  }

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

    /* didn't find it in the current branches, go look in the
     * dead branches */
    $path = $name;
    while (strlen($path)) {
      if (isset($this->dead_branches[$path])) {
        $dead = $this->dead_branches[$path];
        foreach ($dead as $b) {
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

