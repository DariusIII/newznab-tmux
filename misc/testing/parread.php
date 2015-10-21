<?php

require_once realpath(dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'indexer.php');

use newznab\db\Settings;
use newznab\Releases;
use newznab\NZB;
use newznab\NNTP;
use newznab\NZBInfo;

$releases = new Releases();
$pdo = new Settings();
$nzb = new NZB();
$nntp = new NNTP;

// read pars for a release GUID, echo out any that look like a rar
$relguid = "249f9ec1f0d68d33b5fa85594ba1a47d";

$nzbfile = $nzb->getNZBPath($relguid, $pdo->getSetting('nzbpath'), true);
$nzbInfo = new NZBInfo;
$nzbInfo->loadFromFile($nzbfile);

$nntp->doConnect();

echo $nzbInfo->summarize();
foreach($nzbInfo->parfiles as $parfile)
{
    echo "Fetching ".$parfile['subject']."\n";
    $parBinary = $nntp->getMessages($parfile['groups'][0], $parfile['segments']);
    if ($parBinary)
    {
        $par2 = new Par2info();
        $par2->setData($parBinary);
        if (!$par2->error)
        {
           $parFiles = $par2->getFileList();
            foreach($parFiles as $file)
            {
                if (preg_match('/.*part0*1\.rar$/iS', $file['name']) || preg_match('/(?!part0*1)\.rar$/iS', $file['name']) || preg_match('/\.001$/iS', $file['name']))
                {
                    print_r($file);
                }
            }
        }
    }
    unset($parBinary);
}
