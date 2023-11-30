<?php
namespace dynoser\nsmupdate;

use dynoser\autoload\AutoLoader;
use dynoser\nsmupdate\TargetMaps;
use dynoser\nsmupdate\TargetDiff;

class UpdateByNSMaps
{
    public $echoOn = true;
    
    public $tmObj = null;
    
    public $remoteNSMapURLs = null;
    public $loadedNSMapsArr = null;
    public $targetMapsArr = null;
    public $filesMapArr = null;
    public $filesLocalArr = null; // Only local f
    private $allNSInstalledArr = null;
    public $reduced = false;
    
    public $defaultPkgTTL = 3600;
    
    public function __construct(bool $autoRun = true, bool $echoOn = true)
    {        
        $this->echoOn = $echoOn;

        if ($autoRun) {
            $this->update();
        }
    }
    
    public function getTmObj() {
        if (!$this->tmObj) {
            $this->tmObj = new TargetMaps($this->echoOn);
        }
        return $this->tmObj;
    }

    public function msg($msg) {
        $this->getTmObj()->msg($msg);
    }

    public function removeCache() {
        $this->msg("Cache removing: ");
        $tmObj = $this->getTmObj();
        $changed = false;
        $cachedTargetMapFile = $tmObj->cachedTargetMapFile;
        if ($cachedTargetMapFile && \is_file($cachedTargetMapFile) && \unlink($cachedTargetMapFile)) {
            $changed = true;
            $this->msg(" Successful removed chachedTargetMapFile\n");
        }
        $dynoFile = \constant('DYNO_FILE');
        if ($dynoFile && \is_file($dynoFile) && \unlink($dynoFile)) {
            $changed = true;
            $this->msg(" Successful removed DYNO_FILE\n"); 
        }
        echo $changed ? "Complete\n" : "No changes\n";
    }

    public function update(array $onlyNSarr = [], array $skipNSarr = [], array $doNotUpdateFilesArr = [], array $updateByHashesOnlyArr = []) {
        // expand $doNotUpdateFilesArr to full pathes
        foreach($doNotUpdateFilesArr as $k => $prefixedFileName) {
            $fullFileName = AutoLoader::getPathPrefix($prefixedFileName);
            if (!$fullFileName) {
                throw new \Exception("Bad file specification: $prefixedFileName , name must be prefixed. Use '*' prefix for absolut pathes");
            }
            $doNotUpdateFilesArr[$k] = \strtr($fullFileName, '\\', '/');
        }
        
        // check $updateByHashesOnlyArr
        foreach($updateByHashesOnlyArr as $k => $v) {
            if (empty($v) || !\strpos($k, '#')) {
                throw new \Exception("Incorrect updateByHashesOnlyArr (code error)");
            }
        }
        
        $changesArr = $this->lookForDifferences($onlyNSarr, $skipNSarr);
        if (!$changesArr) {
            return null;
        }
        if (!$this->loadedNSMapsArr || !$this->targetMapsArr) {
            throw new \Exception("Code error: loadedNSMapsArr or targetMapsArr not loaded");
        }

        $modifiedFilesArr = $changesArr['modifiedFilesArr'];
        $notFoundFilesMapArr = $changesArr['notFoundFilesMapArr'];

        $tmObj = $this->getTmObj();

        $allNSModifiedArr = TargetDiff::findNSMentionedArr($modifiedFilesArr, $notFoundFilesMapArr);
        $downFilesArr = TargetDiff::prepareDownLoadFilesArr($allNSModifiedArr, $updateByHashesOnlyArr);

        // remove $doNotUpdateFilesArr from $downFilesArr
        foreach($doNotUpdateFilesArr as $fullFileName) {
            if (isset($downFilesArr[$fullFileName])) {
                $tmObj->msg("Skip update for file: $fullFileName \n");
                unset($downFilesArr[$fullFileName]);
            }
        }
        if (!$downFilesArr) {
            return null;
        }
        $newResults = $tmObj->buildTargetMaps($this->loadedNSMapsArr, $this->defaultPkgTTL, \array_keys($allNSModifiedArr), $skipNSarr, null, $downFilesArr);
        $updatedResultsArr = TargetDiff::calcUpdatedFilesFromBuild($newResults);
        if ($this->echoOn) {
            foreach($updatedResultsArr as $nameSpace => $updatedFilesArr) {
                $tmObj->msg("Updated in package: $nameSpace " . \count($updatedFilesArr) . "files: \n");
                foreach($updatedFilesArr as $shortName => $updateArr) {
                    echo "  $shortName => " . $updateArr[1] . "\n";
                }
            }
        }
        return $updatedResultsArr;
    }
    
    public function lookForDifferences(array $onlyNSarr = [], array $skipNSarr = []): ?array {
        $filesLocalArr = $this->getFilesLocalArr($onlyNSarr, $skipNSarr);

        $modifiedFilesArr = TargetDiff::scanModifiedFiles($filesLocalArr);
        $notFoundFilesMapArr = TargetDiff::calcNotFoundArr($this->allNSInstalledArr, $this->targetMapsArr);

        return
            (empty($modifiedFilesArr) && empty($notFoundFilesMapArr)) ? null
            : compact('modifiedFilesArr', 'notFoundFilesMapArr');
    }

    public function getRemoteNSMapURLs() {
        if (!$this->remoteNSMapURLs) {
            $tmObj = $this->getTmObj();
            $tmObj->dynoObjCheckUp();
            $this->remoteNSMapURLs = $tmObj->dynoObj->getCachedRemoteNSMapURLs();
        }
        return $this->remoteNSMapURLs;
    }
    public function setRemoteNSMapURLs(array $remoteNSMapURLs): ?array {
        $this->tmObj->dynoObjCheckUp();
        return $this->tmObj->dynoObj->resetRemoteNSMapURLs($remoteNSMapURLs);
    }

    public function getFilesLocalArr(array $onlyNSarr = [], array $skipNSarr = []): array {
        $tmObj = $this->getTmObj();
        $tmObj->dynoObjCheckUp();
        $this->remoteNSMapURLs = $tmObj->dynoObj->getCachedRemoteNSMapURLs();
        $this->loadedNSMapsArr = $tmObj->downLoadNSMaps($this->remoteNSMapURLs, true);
        $this->targetMapsArr = $tmObj->buildTargetMaps($this->loadedNSMapsArr, $this->defaultPkgTTL, $onlyNSarr, $skipNSarr);
        
        $this->filesMapArr = TargetDiff::targetMapArrToFilesMapArr($this->targetMapsArr, $onlyNSarr, $skipNSarr);
        $this->filesLocalArr = TargetDiff::scanIntersectionFilesMapArr($this->filesMapArr);

        $this->allNSInstalledArr = $this->filesMapArr ? TargetDiff::findNSMentionedArr($this->filesLocalArr) : [];
        $this->reduced = !empty($onlyNSarr) || !empty($skipNSarr);

        return $this->filesLocalArr;
    }
    
    public function getAllNSInstalledArr() {
        if (!$this->allNSInstalledArr || $this->reduced) {
            $this->getFilesLocalArr();
        }
        return $this->allNSInstalledArr;
    }
    
    public function getAllNSKnownArr() {
        if (!$this->allNSInstalledArr || $this->reduced) {
            $this->getFilesLocalArr();
        }

        $allNSknownArr = []; // [nameSpace] => true
        foreach($this->filesMapArr as $fileFull => $hashArr) {
            foreach($hashArr as $hashHex => $lenNSArr) {
                foreach($lenNSArr as $fileLen => $nameSpaceArr) {
                    foreach($nameSpaceArr as $nameSpace) {
                        $allNSknownArr[$nameSpace] = false;
                    }
                }
            }
        }
        
        foreach($this->allNSInstalledArr as $nameSpace => $filesArr) {
            $allNSknownArr[$nameSpace] = \array_keys($filesArr);
        }
        return $allNSknownArr;
    }
}