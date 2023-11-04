<?php
namespace dynoser\nsmupdate;

use dynoser\autoload\AutoLoader;
use dynoser\autoload\AutoLoadSetup;
use dynoser\hashsig\HashSigBase;
use dynoser\autoload\DynoLoader;
use dynoser\tools\HELML;

class TargetMaps
{
    public $dynoIsImp = false;
    public $dynoObj = null;
    
    public string $cachedTargetMapFile = '';
    
    public $echoOn = true;
    
    public function msg($msg) {
        if ($this->echoOn) {
            echo $msg;
        }
    }
    
    public function __construct()
    {
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
        
        if ($this->dynoObj->dynoDir && \class_exists('dynoser\\tools\\HELML')) {
            $this->cachedTargetMapFile = $this->dynoObj->dynoDir . '/targetmap.helml';
        }
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

    public function getTargetMaps(array $remoteNSMapURLs = null, bool $canUsePrepMap = true, array $onlyNSarr = [], array $skipNSarr = [], callable $onEachFile = null, array $downFilesArr = []) {
        $this->dynoObjCheckUp();

        if (!$remoteNSMapURLs) {
            $remoteNSMapURLs = $this->getRemoteNSMapURLs();
        }

        // try download nsmaps from remote urls
        $loadedNSMapsArr = $this->downLoadNSMaps($remoteNSMapURLs, true);
        
        $pkgArrArr = $this->buildTargetMaps($loadedNSMapsArr, $canUsePrepMap, $onlyNSarr, $skipNSarr, $downFilesArr);
        
        return $pkgArrArr;
    }
    
    public function buildTargetMaps(
        array $loadedMapsArr,
        bool $canUsePrepMap = true,
        array $onlyNSarr = [],
        array $skipNSarr = [],
        callable $onEachFile = null,
        array $downFilesArr = []
    ): array {
        $this->dynoObjCheckUp();
        
        if ($this->cachedTargetMapFile && \is_file($this->cachedTargetMapFile)) {
            $fileDataStr = \file_get_contents($this->cachedTargetMapFile);
            $decodedCachedTargedMapArr = HELML::decode($fileDataStr);
        }
        if (empty($decodedCachedTargedMapArr) || !\is_array($decodedCachedTargedMapArr)) {
            $decodedCachedTargedMapArr = [];
        }
        
        if ($downFilesArr && !$onEachFile) {
            $onEachFile = function($hs, $remoteArr, $dlArr) {
                $arr = $hs->hashSignedArr;
                foreach($dlArr['successArr'] as $shortFile => $fileData) {
                    if (isset($arr[$shortFile])) {
                        $arr[$shortFile]['fileData'] = $fileData;
                    }
                }
                return $arr;
            };
        }
        
        $pkgArrArr = []; // [nsMapKey][nameSpace] => array dlArr or string error
        $usedPrepMap = false;
        foreach($loadedMapsArr as $nsMapKey => $dlMapArr) {
            if (empty($dlMapArr['nsMapArr'])) {
                continue;
            }
            if ($canUsePrepMap && $this->cachedTargetMapFile) {
                $targetMap = [];
                if (!empty($dlMapArr['targetMapsArr'])) {
                    foreach($dlMapArr['targetMapsArr'] as $tmName => $fileDataStr) {
                        $un = HELML::decode($fileDataStr);
                        if (\is_array($un)) {
                            $targetMap += $un;
                        }
                    }
                }
                if (!$targetMap && $decodedCachedTargedMapArr) {
                    if (isset($decodedCachedTargedMapArr[$nsMapKey])) {
                        $targetMap = $decodedCachedTargedMapArr[$nsMapKey];
                    }
                }
                if ($targetMap) {
                    $usedPrepMap = true;
                    $pkgArrArr[$nsMapKey] = $targetMap;
                    continue;
                }
            }

            $pkgArrArr[$nsMapKey] = [];
            $nsMapArr = $dlMapArr['nsMapArr'];
            $this->msg("Scan $nsMapKey " . \count($nsMapArr) . " records... ");
            $nsMapRemotesArr = $this->getRemotesFromNSMapArr($nsMapArr);
            $lcnt = \count($nsMapRemotesArr);
            if (!$lcnt) {
                $this->msg("Not found remote links\n");
                continue;
            }
            $this->msg("found $lcnt remote links\nChecking:\n");
            foreach($nsMapRemotesArr as $nameSpace => $remoteArr) {
                if ($onlyNSarr && !\in_array($nameSpace, $onlyNSarr)) {
                    $this->msg("Package '$nameSpace' skip by onlyNSarr\n");
                    continue;
                }

                if (\in_array($nameSpace, $skipNSarr)) {
                    $this->msg("Package '$nameSpace' skip by skipNSarr\n");
                    continue;
                }
                
                if (!\is_array($remoteArr)) {
                    $this->msg("Package '$nameSpace' skip because can't parse this remote definition");
                    continue;
                }
                
                $hashSigRemoteURL = $remoteArr['fromURL'];
                
                $this->msg("Package '$nameSpace' download from: $hashSigRemoteURL\n");
                
                if (!\array_key_exists($nameSpace, $pkgArrArr[$nsMapKey])) {
                    $pkgArrArr[$nsMapKey][$nameSpace] = [];
                }
                
                $hs = new HashSigBase;
                try {
                    $dlArr = $hs->getFilesByHashSig(
                        $hashSigRemoteURL,
                        null,  //$saveToDir
                        null,  //$baseURLs
                        true,  //$doNotSaveFiles
                        false, //$doNotOverWrite
                        false, //$zipOnlyMode
                        null   //$onlyTheseFilesArr
                    );
                    if (\is_array($dlArr)) {
                        $this->msg(" Success files: " . \count($dlArr['successArr']));
                        $errCnt = \count($dlArr['errorsArr']);
                        if ($errCnt) {
                            $this->msg(", Error files: $errCnt");
                        } else {
                            $this->msg(", OK");
                        }
                        if ($onEachFile) {
                            $dlArr = $onEachFile($hs, $remoteArr, $dlArr);
                        } else {
                            $dlArr = $hs->hashSignedArr;
                        }
                        foreach($dlArr as $shortFile => $hsArr) {
                            if (isset($hsArr[0]) && $hsArr[0] === $shortFile) {
                                $targetUnpack = $remoteArr['targetUnpackDir'];
                                if (\substr($targetUnpack, -1) !== '/') {
                                    if (\substr($targetUnpack, -\strlen($shortFile)) === $shortFile) {
                                        $targetUnpack = \substr($targetUnpack, 0, -\strlen($shortFile));
                                    } else {
                                        $targetUnpack .= '/';
                                    }
                                }
                                $prefixedFileName = $targetUnpack . $shortFile;
                                $fullTargetFile = AutoLoader::getPathPrefix($prefixedFileName);
                                if ($fullTargetFile && $downFilesArr && (!empty($downFilesArr[$prefixedFileName]) || !empty($downFilesArr[$fullTargetFile]))) {
                                    if (!empty($hsArr['fileData'])) {
                                        $wb = \file_put_contents($fullTargetFile, $hsArr['fileData']);
                                        if ($wb) {
                                            $dlArr[$shortFile]['fileData'] = [$wb, $fullTargetFile];
                                        }
                                    }
                                }
                                $dlArr[$shortFile][0] = $prefixedFileName;
                            }
                        }
                        $dlArr['*'] = [
                            'checktime' => time(),
                            'hashalg' => $hs->lastPkgHeaderArr['hashalg'],
                            'filescnt' => $hs->lastPkgHeaderArr['filescnt'],
                            'fromurl' => $remoteArr['fromURL'],
                            'target' => $remoteArr['targetUnpackDir'],
                            'chkfile' => $remoteArr['checkFilesStr'],
                            'ns' => $remoteArr['replaceNameSpace'],
                        ];
                        if ($remoteArr['replaceNameSpace'] === $remoteArr['targetUnpackDir']) {
                            unset($dlArr['*']['target']);
                        }
                        $pkgArrArr[$nsMapKey][$nameSpace] = $dlArr;
                    } else {
                        $this->msg("ERROR");
                        $pkgArrArr[$nsMapKey][$nameSpace] = "ERROR (Return not is array)";
                    }
                    $this->msg("\n");
                } catch (\Throwable $e) {
                    $errMsg = $e->getMessage();
                    $pkgArrArr[$nsMapKey][$nameSpace] = $errMsg;
                    $this->msg($errMsg);
                } finally {
                    $this->msg("\n");
                }
            }
        }
        
        if (!$usedPrepMap && $this->cachedTargetMapFile && $pkgArrArr && !$onlyNSarr && !$skipNSarr) {
            $helmlStr = HELML::encode($pkgArrArr);
            if ($helmlStr) {
                $wb = \file_put_contents($this->cachedTargetMapFile, $helmlStr);
            }
        }
        return $pkgArrArr;
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