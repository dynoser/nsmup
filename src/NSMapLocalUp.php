<?php
namespace dynoser\nsmupdate;

use dynoser\autoload\DynoLoader;
use dynoser\HELML\HELML;

class NSMapLocalUp
{
    public string $nsMapBaseDir = '';
    
    const NSMAP_HELML = 'nsmap.helml';
    const TARGETMAP_HELML = 'targetmap.helml';
    
    public $tmbObj = null;
    
    public function __construct(string $nsMapBaseDir, $echoOn = true) {
        $chkPath = \realpath($nsMapBaseDir);
        if (!$chkPath) {
            throw new \Exception("Not found nsMapBaseDir: $nsMapBaseDir");
        }
        $this->nsMapBaseDir = \strtr($chkPath, '\\', '/');
        $this->tmbObj = new TargetMapBuilder($echoOn);
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
        $pkgArr = $this->tmbObj->build(
            $nsMapArr,
            [], //$oldTargetMapArr
            0   //$timeToLivePkgSec = 3600
        );
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