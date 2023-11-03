<?php
namespace dynoser\nsmupdate;

use dynoser\autoload\DynoLoader;
use dynoser\tools\HELML;

class NSMapLocalUp
{
    public string $nsMapBaseDir = '';
    
    const NSMAP_HELML = 'nsmap.helml';
    const TARGETMAP_HELML = 'targetmap.helml';
    
    public function __construct(string $nsMapBaseDir) {
        $chkPath = \realpath($nsMapBaseDir);
        if (!$chkPath) {
            throw new \Exception("Not found nsMapBaseDir: $nsMapBaseDir");
        }
        $this->nsMapBaseDir = \strtr($chkPath, '\\', '/');
    }

    public function run() {
        $nsMapFilesArr = $this->getCurrentNSMapArr();
        foreach($nsMapFilesArr as $nsMapFullFile) {
            $pkgArr = $this->calcTargetMap($nsMapFullFile);
            if (\is_array($pkgArr)) {
                $targetMapFile = \substr($nsMapFullFile,0, -\strlen(self::NSMAP_HELML)) . self::TARGETMAP_HELML;
                $pkgTMStr = HELML::encode($pkgArr);
                $wb = \file_put_contents($targetMapFile, $pkgTMStr);
                if ($wb) {
                    echo "Succesful writed: $targetMapFile \n";
                } else {
                    echo "ERROR writing: $targetMapFile \n";
                }
            } else {
                echo "ERROR loading nsMap from $nsMapFullFile \n";
            }
        }
        echo "Finished\n";
    }
    
    public function calcTargetMap(string $nsMapFullFile): ?array {
        $pkgArr = [];
        echo "Checking $nsMapFullFile ... ";
        $dlMapArr = $this->parseNSMapFile($nsMapFullFile);
        if (empty($dlMapArr['nsMapArr'])) {
            return null;
        }
        $nsMapArr = $dlMapArr['nsMapArr'];
        echo \count($nsMapArr) . " records found\n";
        $nsMapLinksArr = TargetMaps::getRemotesFromNSMapArr($nsMapArr);
        $lcnt = \count($nsMapLinksArr);
        if (!$lcnt) {
            echo "No links found\n";
            return null;
        }
        echo "Verifycation $lcnt links:\n";
        foreach($nsMapLinksArr as $nameSpace => $remoteArr) {
            $fromURL = $remoteArr['fromURL'];
            echo $nameSpace . ': :' . $fromURL . "\n Download... ";

            $hs = new \dynoser\hashsig\HashSigBase;
            try {
                $dlArr = $hs->getFilesByHashSig(
                    $fromURL,
                    null,  //$saveToDir
                    null,  //$baseURLs
                    true,  //$doNotSaveFiles
                    false, //$doNotOverWrite
                    false, //$zipOnlyMode
                    null   //$onlyTheseFilesArr
                );
                if (\is_array($dlArr)) {
                    //echo $hs->hashSignedStr . "\n";
                    echo " Success files: " . \count($dlArr['successArr']);
                    $errCnt = \count($dlArr['errorsArr']);
                    if ($errCnt) {
                        echo ", Error files: $errCnt";
                    } else {
                        echo ", OK";
                        $onePkg = $hs->hashSignedArr;
                        $headArr = $hs->lastPkgHeaderArr;
                        $onePkg['*'] = $headArr;
                        $pkgArr[$nameSpace] = $onePkg; 
                    }
                } else {
                    echo "ERROR";
                }
                echo "\n";
            } catch (\Throwable $e) {
                echo $e->getMessage();
            } finally {
                echo "\n";
            }
        }
        return $pkgArr;
    }

    public static function parseNSMapFile(string $nsMapFullFile) {
        $fileDataStr = \file_get_contents($nsMapFullFile);
        return DynoLoader::parseNSMapHELMLStr($fileDataStr);
    }        
    
    public function getCurrentNSMapArr() {
        return $this->getFilesByMask("*." . self::NSMAP_HELML);
    }
    
    public function getFilesByMask(string $fileMask) {
        $filesArr = \glob($this->nsMapBaseDir . '/' . $fileMask);
        return $filesArr;
    }
}