<?php

require_once dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'bootstrap/autoload.php';

use App\Models\Category;
use App\Models\Release;
use Blacklight\Categorize;
use Blacklight\ColorCLI;
use Blacklight\ConsoleTools;
use Illuminate\Support\Facades\DB;

$colorCli = new ColorCLI();

if (! (isset($argv[1]) && ($argv[1] === 'all' || $argv[1] === 'misc' || preg_match('/\([\d, ]+\)/', $argv[1]) || is_numeric($argv[1])))) {
    $colorCli->error(
        "\nThis script will attempt to re-categorize releases and is useful if changes have been made to Category.php.\n"
        ."No updates will be done unless the category changes\n"
        ."An optional last argument, test, will display the number of category changes that would be made\n"
        ."but will not update the database.\n\n"
        ."php $argv[0] all                     ...: To process all releases.\n"
        ."php $argv[0] misc                    ...: To process all releases in misc categories.\n"
        ."php $argv[0] 155                     ...: To process all releases in groupid 155.\n"
        ."php $argv[0] '(155, 140)'            ...: To process all releases in groupids 155 and 140.\n"
    );
    exit();
}

reCategorize($argv);

function reCategorize($argv)
{
    $colorCli = new ColorCLI();
    $where = '';
    $otherCats = implode(',', Category::OTHERS_GROUP);
    $update = true;
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $where = ' AND groups_id = '.$argv[1];
    } elseif (isset($argv[1]) && preg_match('/\([\d, ]+\)/', $argv[1])) {
        $where = ' AND groups_id IN '.$argv[1];
    } elseif (isset($argv[1]) && $argv[1] === 'misc') {
        $where = sprintf(' AND categories_id IN (%s)', $otherCats);
    }
    if (isset($argv[2]) && $argv[2] === 'test') {
        $update = false;
    }

    if (isset($argv[1]) && (is_numeric($argv[1]) || preg_match('/\([\d, ]+\)/', $argv[1]))) {
        $colorCli->header('Categorizing all releases in '.$argv[1].' using searchname. This can take a while, be patient.');
    } elseif (isset($argv[1]) && $argv[1] === 'misc') {
        $colorCli->header('Categorizing all releases in misc categories using searchname. This can take a while, be patient.');
    } else {
        $colorCli->header('Categorizing all releases using searchname. This can take a while, be patient.');
    }
    $timeStart = now();
    if (isset($argv[1]) && (is_numeric($argv[1]) || preg_match('/\([\d, ]+\)/', $argv[1]) || $argv[1] === 'misc')) {
        $chgCount = categorizeRelease(str_replace(' AND', 'WHERE', $where), $update, true);
    } else {
        $chgCount = categorizeRelease('', $update, true);
    }
    $time = now()->diffInSeconds($timeStart);
    if ($update === true) {
        $colorCli->header('Finished re-categorizing '.number_format($chgCount).' releases in '.$time.' , using the searchname.'.PHP_EOL);
    } else {
        $colorCli->header('Finished re-categorizing in '.$time.' seconds , using the searchname.'.PHP_EOL
            .'This would have changed '.number_format($chgCount).' releases but no updates were done.'.PHP_EOL);
    }
}

// Categorizes releases.
// Returns the quantity of categorized releases.
function categorizeRelease($where, $update = true, $echoOutput = false)
{
    $cat = new Categorize();
    $consoleTools = new ConsoleTools();
    $relCount = $chgCount = 0;
    $consoleTools->primary('SELECT id, searchname, fromname, groups_id, categories_id FROM releases '.$where);
    $resRel = DB::select('SELECT id, searchname, fromname, groups_id, categories_id FROM releases '.$where);
    $total = \count($resRel);
    if ($total > 0) {
        foreach ($resRel as $rowRel) {
            $catId = $cat->determineCategory($rowRel->groups_id, $rowRel->searchname, $rowRel->fromname);
            if ((int) $rowRel->categories_id !== $catId) {
                if ($update === true) {
                    DB::update(
                        sprintf(
                            '
							UPDATE releases
							SET iscategorized = 1,
								videos_id = 0,
								tv_episodes_id = 0,
								imdbid = NULL,
								musicinfo_id = NULL,
								consoleinfo_id = NULL,
								gamesinfo_id = 0,
								bookinfo_id = NULL,
								anidbid = NULL,
								xxxinfo_id = 0,
								categories_id = %d
							WHERE id = %d',
                            $catId['categories_id'],
                            $rowRel->id
                        )
                    );
                    $release = Release::find($rowRel->id);
                    if (! empty($release)) {
                        $release->retag($catId['tags']);
                    }
                }
                $chgCount++;
            }
            $relCount++;
            if ($echoOutput) {
                $consoleTools->overWritePrimary('Re-Categorized: ['.number_format($chgCount).'] '.$consoleTools->percentString($relCount, $total));
            }
        }
    }
    if ($echoOutput !== false && $relCount > 0) {
        echo PHP_EOL;
    }

    return $chgCount;
}
