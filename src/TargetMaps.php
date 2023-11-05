<?php
namespace dynoser\nsmupdate;

use dynoser\autoload\AutoLoader;
use dynoser\autoload\AutoLoadSetup;
use dynoser\hashsig\HashSigBase;
use dynoser\autoload\DynoLoader;
use dynoser\tools\HELML;
use dynoser\nsmupdate\TargetMapBuilder;

class TargetMaps
{
    public $dynoIsImp = false;
    public $dynoObj = null;
    
    public string $cachedTargetMapFile = '';
    
    public $tmbObj = null;
    
    public $echoOn = true;
    
    public function msg($msg) {
        if ($this->echoOn) {
            echo $msg;
        }
    }
    
    public function __construct(bool $echoOn = true)
    {
        $this->echoOn = $echoOn;
        
        if (!\class_exists('dynoser\autoload\AutoLoadSetup', false)) {
            throw new \Exception("dynoser/autoload must be load before");
        }
        $this->dynoObj = AutoLoadSetup::$dynoObj;
        
        if (!$this->dynoObj) {
            throw new \Exception("No dynamic loading mode in the autoloader (dynoObj DynoLoader)");
        }
        
        if (!$this->dynoObj->vendorDir) {
            throw new \Exception("Empty vendorDir in dynoObj");
        }

        if (!$this->dynoObj->dynoDir) {
            $this->dynoObj->checkCreateDynoDir($this->dynoObj->vendorDir);
        }
        
        if ($this->dynoObj->dynoDir) {
            $this->cachedTargetMapFile = $this->dynoObj->dynoDir . '/targetmap.php';
        }
        
        $this->tmbObj = new TargetMapBuilder($this->echoOn);
    }
    
    public function dynoObjCheckUp() {
        if (!$this->dynoObj) {
            throw new \Exception("dynoObj not found, code error");
        }
        // upgrade $this->dynoObj from DynoLoader to DynoImporter (if need)
        if (!$this->dynoIsImp) {
            $this->dynoObj = $this->dynoObj->makeDynoImporterObj();
            $this->dynoIsImp = true;
        }        
    }
    
    public function getRemoteNSMapURLs(): array {
        $this->dynoObjCheckUp();
        
        // load all current records from nsmap local cache
        $nsMapArr = $this->dynoObj->loadNSMapFile();
        if (!$nsMapArr) {
            // no nsmap in cache, try rebuild nsmap cache
            $nsMapArr = $this->dynoObj->rebuildDynoCache();
            if (!$nsMapArr) {
                // can't rebuild, we don't have nsmap urls
                throw new \Exception("No nsmap data");
            }
        }
        
        // get remote-nsmap list
        $remoteNSMapURLs = $nsMapArr[DynoLoader::REMOTE_NSMAP_KEY] ?? [];

        return $remoteNSMapURLs;
    }

//    public function getTargetMaps(
//        array $remoteNSMapURLs = null,
//        int $timeToLivePkgSec = 3600,
//        array $onlyNSarr = [],
//        array $skipNSarr = [],
//        callable $onEachFile = null,
//        array $downFilesArr = []
//    ) {
//        $this->dynoObjCheckUp();
//
//        if (!$remoteNSMapURLs) {
//            $remoteNSMapURLs = $this->getRemoteNSMapURLs();
//        }
//
//        // try download nsmaps from remote urls
//        $loadedNSMapsArr = $this->downLoadNSMaps($remoteNSMapURLs, true);
//        
//        $pkgArrArr = $this->buildTargetMaps($loadedNSMapsArr, $timeToLivePkgSec, $onlyNSarr, $skipNSarr, $onEachFile, $downFilesArr);
//        
//        return $pkgArrArr;
//    }
    
    public function buildTargetMaps(
        array $loadedMapsArr,
        int $timeToLivePkgSec = 3600,
        array $onlyNSarr = [],
        array $skipNSarr = [],
        callable $onEachFile = null,
        array $downFilesArr = []
    ): array {
        $this->dynoObjCheckUp();
        
        if ($this->cachedTargetMapFile && empty($downFilesArr) && \is_file($this->cachedTargetMapFile)) {
            $decodedCachedTargedMapArr = $this->loadTargetMapFile();
        }
        if (empty($decodedCachedTargedMapArr) || !\is_array($decodedCachedTargedMapArr)) {
            $decodedCachedTargedMapArr = [];
        }
        
        // file downloading:
        if ($downFilesArr && !$onEachFile) {
            $onEachFile = function($hs, $remoteArr, $dlArr) use($downFilesArr) {
                $outArr = $hs->hashSignedArr;
                foreach($dlArr['successArr'] as $shortFile => $fileData) {
                    if (isset($outArr[$shortFile])) {
                        $outArr[$shortFile]['fileData'] = $fileData;
                    }
                }
                
                foreach($outArr as $shortFile => $hsArr) {
                    if (isset($hsArr[0]) && $hsArr[0] === $shortFile) {
                        $targetUnpack = $remoteArr['targetUnpackDir'];
                        if (\substr($targetUnpack, -1) !== '/') {
                            if (\substr($targetUnpack, -\strlen($shortFile)) === $shortFile) {
                                $targetUnpack = \substr($targetUnpack, 0, -\strlen($shortFile));
                            } else {
                                $targetUnpack = \dirname($targetUnpack);
                            }
                        }
                        $prefixedFileName = $targetUnpack . $shortFile;
                        $fullTargetFile = AutoLoader::getPathPrefix($prefixedFileName);
                        if ($fullTargetFile && $downFilesArr && (!empty($downFilesArr[$prefixedFileName]) || !empty($downFilesArr[$fullTargetFile]))) {
                            if (!empty($hsArr['fileData'])) {
                                $wb = \file_put_contents($fullTargetFile, $hsArr['fileData']);
                                if ($wb) {
                                    $outArr[$shortFile]['fileData'] = [$wb, $fullTargetFile];
                                }
                            }
                        }
                        $outArr[$shortFile][0] = $prefixedFileName;
                    }
                }
                return $outArr;
            };
        }
        
        $pkgArrArr = []; // [nsMapKey][nameSpace] => array dlArr or string error
        foreach($loadedMapsArr as $nsMapKey => $dlMapArr) {
            if (empty($dlMapArr['nsMapArr'])) {
                continue;
            }
            $oldTargetMapArr = $decodedCachedTargedMapArr[$nsMapKey] ?? [];

            if ($timeToLivePkgSec && empty($downFilesArr)) {
                if (!empty($dlMapArr['targetMapsArr'])) {
                    foreach($dlMapArr['targetMapsArr'] as $tmName => $fileDataStr) {
                        $targetMapFromPkgArr = HELML::decode($fileDataStr);
                        if (\is_array($targetMapFromPkgArr)) {
                            TargetMapBuilder::targetMapMerge($oldTargetMapArr, $targetMapFromPkgArr);
                        }
                    }
                }
            }

            $nsMapArr = $dlMapArr['nsMapArr'];
            if (empty($downFilesArr)) {
                $this->msg("Scan $nsMapKey " . \count($nsMapArr) . " records... ");
            } else {
                $this->msg("Download mode ON ...");
                $oldTargetMapArr = [];
            }
            
            $pkgArrArr[$nsMapKey] = $this->tmbObj->build(
                $nsMapArr,
                $oldTargetMapArr,
                $timeToLivePkgSec,
                $onlyNSarr,
                $skipNSarr,
                $onEachFile,
                empty($downFilesArr) ? '' : (" Target files:\n *" . \implode("\n *", \array_keys($downFilesArr)) . "\n")
            );
        }
        
        
        if ($this->cachedTargetMapFile && $pkgArrArr && empty($downFilesArr)) {
            $this->saveTargetMapFile($pkgArrArr);
        }
        return $pkgArrArr;
    }
    
    public function loadTargetMapFile(): ?array {
        if ($this->cachedTargetMapFile && \is_file($this->cachedTargetMapFile)) {
            $targetMapArr = (require $this->cachedTargetMapFile);
            if (\is_array($targetMapArr)) {
                return $targetMapArr;
            }
        }
        return null;
    }

    public function saveTargetMapFile(array $targetMapArr) {
        $dataStr = '<' . "?php\n" . 'return ' . \var_export($targetMapArr, true) . ";\n";
        $wb = \file_put_contents($this->cachedTargetMapFile, $dataStr);
        if (!$wb) {
            throw new \Exception("Can't write targetMap cache file: " . $this->cachedTargetMapFile);
        }
    }
    
    public static function getRemotesFromNSMapArr(array $nsMapArr): array {
        $nsMapRemotesArr = []; // [namespace] => link
        foreach($nsMapArr as $nameSpace => $nsMapRow) {
            do {
                $i = \strpos($nsMapRow, ' ');
                if ($i === false) {
                    continue 2;
                }
                if (!$i) {
                    $nsMapRow = \substr($nsMapRow, 1);
                }
            } while (!$i);
            if ($nsMapRow[0] !== ':') {
                continue;
            }
            $fromURL = \substr($nsMapRow, 1, $i - 1);
            $un = DynoLoader::pasreNsMapStr($nsMapRow);
            if ($un['fromURL'] === $fromURL) {
                $nsMapRemotesArr[$nameSpace] = $un; // array
            } else {
                $nsMapRemotesArr[$nameSpace] = $nsMapRow; // string
            }
        }
        return $nsMapRemotesArr;
    }
    
    public function downLoadNSMaps(array $remoteNSMapURLs, bool $getTargetMaps = true): array {
        $loadedMapsArr = []; // [nsmap url (without keys) ]
        foreach($remoteNSMapURLs as $nsMapURL) {
            
            $nsMapKey = \substr($nsMapURL, 0, \strcspn($nsMapURL, '|#'));
            
            $this->msg("Checking $nsMapKey ... ");
            
            if (\array_key_exists($nsMapKey, $loadedMapsArr) && \is_array($loadedMapsArr[$nsMapKey])) {
                $this->msg("Already loaded");
                continue;
            }

            try {
                $loadedMapsArr[$nsMapKey] = $this->dynoObj->downLoadNSMapFromURL($nsMapURL, $getTargetMaps);// nsMapArr specArrArr
                $this->msg("Successful download");
                if (empty($loadedMapsArr[$nsMapKey]['nsMapArr'])) {
                    $this->msg(" (empty)");
                } else {
                    $this->msg(" " . \count($loadedMapsArr[$nsMapKey]['nsMapArr']) . " records");
                }
            } catch (\Throwable $e) {
                $errMsg = $e->getMessage();
                $this->msg("Error: " . $errMsg);
                $loadedMapsArr[$nsMapKey] = $errMsg;
            }
            $this->msg("\n");            
        }
        return $loadedMapsArr;
    }
}