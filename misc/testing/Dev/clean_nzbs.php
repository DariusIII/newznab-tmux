<?php

require_once dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'bootstrap/autoload.php';

use App\Models\Settings;
use Blacklight\ColorCLI;
use Blacklight\NZB;
use Blacklight\ReleaseImage;
use Blacklight\Releases;
use Blacklight\utility\Utility;

$dir = NN_RES.'movednzbs/';
$colorCli = new ColorCLI();

if (! isset($argv[1]) || ! in_array($argv[1], ['true', 'move'])) {
    $colorCli->error("This script can remove all nzbs not found in the db and all releases with no nzbs found. It can also move invalid nzbs.\n\n"
        ."php $argv[0] true     ...: For a dry run, to see how many would be moved.\n"
        ."php $argv[0] move     ...: Move NZBs that are possibly bad or have no release. They are moved into this folder: $dir");
    exit();
}

if (! is_dir($dir) && ! mkdir($dir) && ! is_dir($dir)) {
    exit("ERROR: Could not create folder [$dir].".PHP_EOL);
}

$releases = new Releases();
$nzb = new NZB();
$releaseImage = new ReleaseImage();

$timestart = date('r');
$checked = $moved = 0;
$couldbe = ($argv[1] === 'true') ? 'could be ' : '';

$colorCli->header('Getting List of nzbs to check against db.');
$colorCli->header("Checked / {$couldbe}moved\n");

$dirItr = new \RecursiveDirectoryIterator(Settings::settingValue('..nzbpath'));
$itr = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::LEAVES_ONLY);

foreach ($itr as $filePath) {
    $guid = stristr($filePath->getFilename(), '.nzb.gz', true);
    if (is_file($filePath) && $guid) {
        $nzbfile = Utility::unzipGzipFile($filePath);
        $nzbContents = $nzb->nzbFileList($nzbfile, ['no-file-key' => false, 'strip-count' => true]);
        if (! $nzbfile || ! @simplexml_load_string($nzbfile) || count($nzbContents) === 0) {
            if ($argv[1] === 'move') {
                rename($filePath, $dir.$guid.'.nzb.gz');
            }
            $releases->deleteSingle(['g' => $guid, 'i' => false], $nzb, $releaseImage);
            $moved++;
        }
        $checked++;
        echo "$checked / $moved\r";
    }
}

$colorCli->header("\n".number_format($checked).' nzbs checked, '.number_format($moved).' nzbs '.$couldbe.'moved.');
$colorCli->header('Getting List of releases to check against nzbs.');
$colorCli->header("Checked / releases deleted\n");

$checked = $deleted = 0;

$res = DB::select('SELECT id, guid, nzbstatus FROM releases');
    foreach ($res as $row) {
        $nzbpath = $nzb->getNZBPath($row->guid);
        if (! is_file($nzbpath)) {
            $deleted++;
            $releases->deleteSingle(['g' => $row->guid, 'i' => $row->id], $nzb, $releaseImage);
        } elseif ($row->nzbstatus !== 1) {
            DB::update(sprintf('UPDATE releases SET nzbstatus = 1 WHERE id = %d', $row->id));
        }
        $checked++;
        echo "$checked / $deleted\r";
    }
$colorCli->header("\n".number_format($checked).' releases checked, '.number_format($deleted).' releases deleted.');
$colorCli->header("Script started at [$timestart], finished at [".date('r').']');
