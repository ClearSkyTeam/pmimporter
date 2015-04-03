<?php
if (ini_get('phar.readonly')) {
  $cmd = escapeshellarg(PHP_BINARY);
  $cmd .= ' -d phar.readonly=0';
  foreach ($argv as $i) {
    $cmd .= ' '.escapeshellarg($i);
  }
  passthru($cmd,$rv);
  exit($rv);
}
define('CMD',array_shift($argv));
error_reporting(E_ALL);

/*
 * Build script
 */
$plug = "plugin";
$plug = preg_replace('/\/*$/',"",$plug).'/';
if (!is_dir($plug)) die("$plug: directory doesn't exist!\n");
if (!is_file($pluginYml = $plug."plugin.yml"))
  die("missing plugin manifest\n");
if (!is_dir($srcDir = $plug."src/")) die("Source folder not found\n");

$p = new Phar('pmimporter.phar',
	      FilesystemIterator::CURRENT_AS_FILEINFO
	      | FilesystemIterator::KEY_AS_FILENAME,
	      'pmimporter.phar');

// issue the Phar::startBuffering() method call to buffer changes made to the
// archive until you issue the Phar::stopBuffering() command
$p->startBuffering();

// set the Phar file stub
// the file stub is merely a small segment of code that gets run initially 
// when the Phar file is loaded, and it always ends with a __HALT_COMPILER()

$p->setStub('<?php Phar::mapPhar(); include "phar://pmimporter.phar/main.php"; __HALT_COMPILER(); ?>');
if ($plug) $p->setSignatureAlgorithm(Phar::SHA1);

foreach (['main.php'] as $f) {
  echo ("- $f\n");
  $p[$f] = file_get_contents($f);
}

$help = "Available sub-commands:\n";
foreach (glob('scripts/*.php') as $f) {
  $f = preg_replace('/^scripts\//','',$f);
  $f = preg_replace('/\.php$/','',$f);
  $help .= "\t$f\n";
}
$help .= "\tversion\n";
$help .= "\tplugin\n";
$help .= "\treadme\n";
$p['scripts/help.php'] = $help;
$p['scripts/version.php'] = "<?php require_once(CLASSLIB_DIR.'version.txt');";
$p['scripts/readme.php'] = file_get_contents('README.md');

$dirs=['classlib','scripts'];
while(count($dirs)) {
  $d = array_shift($dirs);
  $dh = opendir($d) or die("$d: unable to open directory\n");
  while (false !== ($f = readdir($dh))) {
    if ($f == '.' || $f == '..') continue;
    $fpath = "$d/$f";
    if (is_dir($fpath)) {
      if (!is_link($fpath)) array_push($dirs,$fpath);
      continue;
    }
    if (!is_file($fpath)) continue;
    if (preg_match('/\.php$/',$f) || preg_match('/\.txt$/',$f)) {
      echo("- $fpath\n");
      $p[$fpath] = file_get_contents($fpath);
    }
  }
  closedir($dh);
}

$pmversion = preg_replace('/\s*pmimporter\s*/','',file_get_contents("version.txt"));
$yml = file_get_contents("plugin/plugin.yml");
$yml = str_replace("<PMIMPORTER>",$pmversion,$yml);
$p["plugin.yml"] = $yml;
if (preg_match('/\n\s*version: ([^\s]+)\s*\n/',$yml,$mv)) {
  echo "Plugin Version: $mv[1]\n";
  $p['scripts/plugin.php'] = "ImportMap v$mv[1]\n";
} else {
  $p['scripts/plugin.php'] = "generic ImportMap\n";
}

if ($plug) {
  echo("Adding sources...\n");
  $cnt = 0;
  foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plug)) as $s){
    if (!is_file($s)) continue;
    $cnt++;
    $d = substr($s,strlen($plug));
    if ($d == "plugin.yml") continue;
    echo("  [$cnt] $d\n");
    $p->addFile(realpath($s),$d);
  }
}

// COMMENTED THIS OUT AS COMPRESSING WAS GENERATING CORRUPTED ARCHIVES!
//$p->compressFiles(Phar::GZ);

//Stop buffering write requests to the Phar archive, and save changes to disk
$p->stopBuffering();
//echo "my.phar archive has been saved";
