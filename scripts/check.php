<?php
if (!defined('CLASSLIB_DIR'))
	require_once(dirname(realpath(__FILE__)).'/../classlib/autoload.php');

use pmimporter\LevelFormatManager;
use pmimporter\anvil\Anvil;
use pmimporter\mcpe020\McPe020;
use pmimporter\pm13\Pm13;
use pmimporter\mcregion\McRegion;
use pmimporter\leveldb\LevelDB;

define('CMD',array_shift($argv));
// Handle options
$minx = $minz = $maxx = $maxz = null;
$chkchunks = false;
while (count($argv) > 0) {
	if (substr($argv[0],0,2) != "--") break;
	$opt = array_shift($argv);
	if (($val = preg_replace('/^--min-x=/','',$opt)) != $opt) {
		$minx = (int)$val;
	} elseif (($val = preg_replace('/^--max-x=/','',$opt)) != $opt) {
		$maxx = (int)$val;
	} elseif (($val = preg_replace('/^--min-z=/','',$opt)) != $opt) {
		$minz = (int)$val;
	} elseif (($val = preg_replace('/^--max-z=/','',$opt)) != $opt) {
		$maxz = (int)$val;
	} elseif (($val = preg_replace('/^--x=/','',$opt)) != $opt) {
		$minx = $maxx = (int)$val;
	} elseif (($val = preg_replace('/^--z=/','',$opt)) != $opt) {
		$minz = $maxz = (int)$val;
	} elseif ($opt == "--check-chunks") {
		$chkchunks = true;
	} elseif ($opt == "--no-check-chunks") {
		$chkchunks = false;
	} else {
		die("Invalid option: $opt\n");
	}
}


$wpath=array_shift($argv);
if (!isset($wpath)) die("No path specified\n");
if (!file_exists($wpath)) die("$wpath: does not exist\n");


LevelFormatManager::addFormat(Anvil::class);
LevelFormatManager::addFormat(McRegion::class);
//LevelFormatManager::addFormat(McPe020::class);
//LevelFormatManager::addFormat(Pm13::class);
//if (extension_loaded("leveldb")) LevelFormatManager::addFormat(LevelDB::class);

$fmt = LevelFormatManager::getFormat($wpath);
if ($fmt === null) die("$wpath: unrecognized format\n");
$level  = new $fmt($wpath);
echo "FORMAT:    ".$level::getFormatName()."\n";
echo "SEED:      ".$level->getSeed()."\n";
echo "Generator: ".$level->getGenerator()."\n";
echo "Presets:   ".$level->getPresets()."\n";
$spawn = $level->getSpawn();
echo "Spawn:     ".implode(',',[$spawn->getX(),$spawn->getY(),$spawn->getZ()])."\n";

$chunks = $level->getChunks();
echo "Chunks:    ".count($chunks)."\n";

if (!$chkchunks) exit(0);
foreach ($chunks as $chunk) {
	list($cx,$cz) = $chunk;

	if ( ($minx !== null && $cx < $minx) || ($maxx !== null && $cx > $maxx) ||
			 ($minz !== null && $cz < $minz) || ($maxz !== null && $cz > $maxz)) continue;

	$chunk = $level->getChunk($cx,$cz);
}

/*
echo "Regions:";
$regions = $fmt->getRegions();
foreach ($regions as $pp) {
	list($rX,$rZ) = $pp;
	echo " $rX,$rZ";
}
echo "\n";

function incr(&$stats,$attr) {
	if (isset($stats[$attr])) {
		++$stats[$attr];
	} else {
		$stats[$attr] = 1;
	}
}

function analyze_chunk(Chunk $chunk,&$stats) {
	if ($chunk->isPopulated()) incr($stats,"-populated");
	if ($chunk->isGenerated()) incr($stats,"-generated");

	for ($x = 0;$x < 16;$x++) {
		for ($z=0;$z < 16;$z++) {
			for ($y=0;$y < 128;$y++) {
				list($id,$meta) = $chunk->getBlock($x,$y,$z);
				incr($stats,$id);
			}
			$height = $chunk->getHeightMap($x,$z);
			if (!isset($stats["Height:Max"])) {
				$stats["Height:Max"] = $height;
			} elseif ($height > $stats["Height:Max"]) {
				$stats["Height:Max"] = $height;
			}
			if (!isset($stats["Height:Min"])) {
				$stats["Height:Min"] = $height;
			} elseif ($height < $stats["Height:Min"]) {
				$stats["Height:Min"] = $height;
			}
			if (!isset($stats["Height:Sum"])) {
				$stats["Height:Sum"] = $height;
			} else {
				$stats["Height:Sum"] += $height;
			}
			incr($stats,"Height:Count");
		}
	}
	foreach ($chunk->getEntities() as $entity) {
		if (!isset($entity->id)) continue;
		if ($entity->id->getValue() == "Item") {
			incr($stats,"ENTITY:Item:".$entity->Item->id->getValue());
			continue;
		}
		incr($stats,"ENTITY:".$entity->id->getValue());

	}
	foreach ($chunk->getTileEntities() as $tile) {
		if (!isset($tile->id)) continue;
		incr($stats,"TILE:".$tile->id->getValue());
	}
}

if (isset($argv[0]) && $argv[0] == '--all') {
	// Process all regions
	$argv = array_keys($regions);
	echo "Analyzing ".count($regions)." regions\n";
}

foreach ($argv as $ppx) {
	$ppx = explode(':',$ppx,2);
	$pp = array_shift($ppx);

	if (!isset($regions[$pp])) die("Region $pp does not exist\n");
	echo " Reg: $pp ";
	$chunks = 0;
	list($rX,$rZ) = $regions[$pp];
	$region = $fmt->getRegion($rX,$rZ);
	$chunks = 0;
	$stats = [];

	if (count($ppx)) {
		foreach (explode('+',$ppx[0]) as $cp) {
			$cp = explode(',',$cp);
			if (count($cp) != 2) die("Invalid chunk ids: ".$ppx[0].NL);
			list($oX,$oZ) = $cp;
			if (!is_numeric($oX) || !is_numeric($oZ)) die("Not numeric $oX,$oZ\n");
			if ($oX < 0 || $oZ < 0 || $oX >= 32 || $oZ >= 32)
				die("Invalid location $oX,$oZ\n");
			if ($region->chunkExists($oX,$oZ)) {
				++$chunks;
				$chunk = $region->readChunk($oX,$oZ);
				if ($chunk)
					analyze_chunk($chunk,$stats);
				else
					echo "Unable to read chunk: $oX,$oZ\n";
			}
		}
	} else {
		for ($oX=0;$oX < 32;$oX++) {
			//for ($oX=7;$oX < 8;$oX++) {
			$cX = $rX*32+$oX;
			for ($oZ=0;$oZ < 32; $oZ++) {
				if (!($oZ % 16)) echo ".";
				//for ($oZ=6;$oZ < 9; $oZ++) {
				$cZ = $rZ*32+$oZ;
				if ($region->chunkExists($oX,$oZ)) {
					++$chunks;
					$chunk = $region->readChunk($oX,$oZ);
					if ($chunk) analyze_chunk($chunk,$stats);
				}
			}
		}
	}
	echo "\n";
	unset($region);
	echo "  Chunks:\t$chunks\n";
	if (isset($stats["Height:Count"]) && isset($stats["Height:Sum"])) {
		$stats["Height:Avg"] = $stats["Height:Sum"]/$stats["Height:Count"];
		unset($stats["Height:Count"]);
		unset($stats["Height:Sum"]);
	}
	$sorted = array_keys($stats);
	natsort($sorted);
	foreach ($sorted as $k) {
		if (is_numeric($k)) {
			$v = Blocks::getBlockById($k);
			$v = $v !== null ? "$v ($k)" : "*Unsupported* ($k)";
		} else {
			$v = $k;
		}
		echo "  $v:\t".$stats[$k].NL;
	}
}
*/
