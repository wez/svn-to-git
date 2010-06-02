<?php # vim:ts=2:sw=2:et:
/* Copyright (c) 2010 Message Systems, Inc.
 * Author: Wez Furlong
 * License: Modified BSD, See LICENSE file.
 */

class SvnDumpNode {
  /** location within the repo */
  public $path;
  public $revision;

  /** the type of node: 'dir', 'file' */
  public $kind;

  /** what action took place: 'add' */
  public $action;

  /** properties of the node */
  public $props = array();

  /** misc metadata */
  public $meta;

  /** the size of the text content for files */
  public $size = 0;
  /** starting offset of file payload in the dump file */
  public $start = 0;
  /** md5 checksum */
  public $md5;
  /** sha1 checksum */
  public $sha1;

  function getText(SvnDumpReader $R) {
    fseek($R->fp, $this->start);
    $data = stream_get_contents($R->fp, $this->size);
    if (sha1($data) != $this->sha1) {
      throw new Exception(
        "SHA1 signature mismatch got $sha1 expected $this->sha1");
    }
    return $data;
  }

  function streamText(SvnDumpReader $R, $target, $verify = false) {
    fseek($R->fp, $this->start);
    if ($verify) {
      $h = hash_init('sha1');
      $total = 0;
      while ($total < $this->size) {
        $x = min($this->size - $total, 8192);
        $data = fread($R->fp, $x);
        if ($data === false) {
          throw new Exception("Reached EOF while reading node text!");
        }
        hash_update($h, $data);

        if (fwrite($target, $data) != strlen($data)) {
          throw new Exception("Failed to write node text payload to target!");
        }
        $total += strlen($data);
      }
      $sha1 = hash_final($h);
      if ($sha1 != $this->sha1) {
        throw new Exception(
            "SHA1 signature mismatch got $sha1 expected $this->sha1");
      }
    } else {
      if (stream_copy_to_stream($R->fp, $target, $this->size) != $this->size) {
        throw new Exception("Error while copying node text payload");
      }
    }
  }
}

/** This class allows walking through a subversion svndump
 * file, changeset by changeset, returning metadata and
 * optionally extracting the file contents, either to memory
 * or to another target stream */
class SvnDumpReader {
  /** location of dump file on disk */
  public $path;
  /** the UUID of the repo from the dump file */
  public $uuid;
  /** the dump format version */
  public $vers;

  /* record-level data */

  /** the current revision */
  public $revision = 0;
  /** the properties of the current revision */
  public $revprops = array();
  /** the nodes in the current revision */
  public $nodes = array();

  /** @private stream handle */
  public $fp;
  /** @private offset to next record */
  public $offset = 0;

  /** hash of revision to node offset */
  public $revoff = array();

  function __construct($path)
  {
    $this->path = $path;
    $this->fp = fopen($path, 'rb');

    $k = $this->readkv();
    if (!isset($k['svn-fs-dump-format-version'])) {
      var_dump($k);
      throw new Exception("Expected dump version");
    }
    $this->vers = $k['svn-fs-dump-format-version'];
    if ($this->vers != '2') {
      var_dump($k);
      throw new Exception("I can only handle version 2, got $this->vers");
    }

    $k = $this->readkv();
    if (!isset($k['uuid'])) {
      var_dump($k);
      throw new Exception("Expected uuid");
    }
    $this->uuid = $k['uuid'];

    $this->offset = ftell($this->fp);
  }

  public function getByRev($rev) {
    if (!isset($this->revoff[$rev])) {
      throw new Exception("unknown revision $rev");
    }
    $this->offset = $this->revoff[$rev];
    return $this->next();
  }

  public function rewind() {
    $this->offset = $this->revoff[0];
  }

  public function next() {
    /* make sure we are where we need to be; node consumers may have
     * seeked us around */
    fseek($this->fp, $this->offset);

    $k = $this->readkv();
    if (count($k) == 0) {
      return false;
    }
    $this->revision = (int)$k['revision-number'];
    $this->revoff[$this->revision] = $this->offset;

    $plen = (int)$k['prop-content-length'];
    if ($k['content-length'] != $plen) {
      throw new Exception(
        "expected content-length to equal prop-content-length");
    }
//    var_dump($k);

    $this->revprops = $this->readprops($plen);

    $blank = trim(fgets($this->fp));
    if ($blank != '') {
      throw new Exception("Expected a blank line, got $blank");
    }
    $this->offset = ftell($this->fp);
    $this->nodes = array();

    /* look ahead; if we see a new revprop block, we have no nodes */
    $k = $this->readkv();
    if (isset($k['revision-number'])) {
      /* no nodes */
      fseek($this->fp, $this->offset);
    } else {
      /* we just read the prop data for the first node */
      do {
        $here = ftell($this->fp);

        $node = new SvnDumpNode;
        $node->revision = $this->revision;
        $node->meta = $k;
        $node->path = $k['node-path'];
        $node->kind = $k['node-kind'];
        $node->action = $k['node-action'];
        if (isset($k['prop-content-length'])) {
          $node->props = $this->readprops($k['prop-content-length']);
        }
        if (isset($k['text-content-length'])) {
          $node->size = (int)$k['text-content-length'];
          $node->md5 = $k['text-content-md5'];
          $node->sha1 = $k['text-content-sha1'];
          $node->start = ftell($this->fp);
        }

        $this->nodes[] = $node;

        if (isset($k['content-length'])) {
          fseek($this->fp, $here + $k['content-length'] + 1);
        }

        /* getting back into sync.
         * When we get here, readkv() has already read the blank line
         * after the metadata for this node.
         * There may be multiple blank lines that follow.
         * I don't understand why this is variable, but let's adapt */
        do {
          $last = ftell($this->fp);
          $line = fgets($this->fp);
          if ($line === false) {
            /* EOF */
            $this->offset = $last;
            return true;
          }
          if (trim($line) == '') {
            continue;
          }
          fseek($this->fp, $last);
          break;
        } while (true);

        $this->offset = ftell($this->fp);
        // look ahead
        $k = $this->readkv();
        if (isset($k['revision-number'])) {
          fseek($this->fp, $this->offset);
          return true;
        }
      } while (count($k));
    }
    return true;
  }

  protected function readprops($len) {
    $props = array();
    $pdata = fread($this->fp, $len);

    $i = 0;
    $K = null;
    $V = null;

    /* binary safe prop reader */
    while ($i < strlen($pdata)) {
      $eol = strpos($pdata, "\n", $i);
      if ($eol === false) {
        var_dump($pdata);
        throw new Exception("Unexpected end of prop data at offset $i");
      }
      $pline = substr($pdata, $i, $eol - $i);
      if ($pline == 'PROPS-END') {
        break;
      }
      if (!preg_match("/^([KV])\s+(\d+)\s*$/", $pline, $M)) {
        throw new Exception("Unexpected prop data line $pline (i=$i,eol=$eol)");
      }
      $name = $M[1];
      $len = $M[2];

      $data = substr($pdata, $eol + 1, $len);

      if ($name == 'K') {
        $K = $data;
      } else {
        $props[$K] = $data;
        $K = null;
      }

      $i = $eol + $len + 2;
    }
    return $props;
  }

  protected function readkv() {
    $keys = array();

    do {
      $line = rtrim(fgets($this->fp));
      if (!strlen($line)) {
        break;
      }

      if (!preg_match("/^(\S+):\s+(.*)\s*$/", $line, $M)) {
        throw new Exception("Unexpected kv line: $line");
      }

      $keys[strtolower($M[1])] = $M[2];
    } while (true);

    return $keys;
  }
}

/*
$R = new SvnDumpReader('ec.svn.dump');

do {
  if (!$R->next()) {
    break;
  }

  echo "Revision $R->revision, ", count($R->nodes), " nodes\n";
  foreach ($R->nodes as $node) {
    if ($node->size) {
      $node->streamText($R, STDOUT);
      exit;
    }
  }
  //var_dump($R);
} while (true);
*/

