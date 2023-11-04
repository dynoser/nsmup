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
            $this->tmObj = new TargetMaps();
            $this->tmObj->echoOn = $this->echoOn;
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

    public function update(array $onlyNSarr = [], array $skipNSarr = [], array $doNotUpdateFilesArr = []) {
        // expand $doNotUpdateFilesArr to full pathes
        foreach($doNotUpdateFilesArr as $k => $prefixedFileName) {
            $fullFileName = AutoLoader::getPathPrefix($prefixedFileName);
            if (!$fullFileName) {
                throw new \Exception("Bad file specification: $prefixedFileName , name must be prefixed. Use '*' prefix for absolut pathes");
            }
            $doNotUpdateFilesArr[$k] = \strtr($fullFileName, '\\', '/');
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
        $downFilesArr = TargetDiff::prepareDownLoadFilesArr($allNSModifiedArr);

        // remove $doNotUpdateFilesArr from $downFilesArr
        foreach($doNotUpdateFilesArr as $fullFileName) {
            if (isset($downFilesArr[$fullFileName])) {
                if ($this->echoOn) {
                    $tmObj->msg("Skip update for file: $fullFileName \n");
                }
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

    public function getFilesLocalArr(array $onlyNSarr = [], array $skipNSarr = []): array {
        $tmObj = $this->getTmObj();

        $this->remoteNSMapURLs = $tmObj->getRemoteNSMapURLs();
        $this->loadedNSMapsArr = $tmObj->downLoadNSMaps($this->remoteNSMapURLs, true);
        $this->targetMapsArr = $tmObj->buildTargetMaps($this->loadedNSMapsArr, $this->defaultPkgTTL, $onlyNSarr, $skipNSarr);
        
        $filesMapArr = TargetDiff::targetMapArrToFilesMapArr($this->targetMapsArr, $onlyNSarr, $skipNSarr);
        $filesLocalArr = TargetDiff::scanIntersectionFilesMapArr($filesMapArr);

        $this->allNSInstalledArr = $filesMapArr ? TargetDiff::findNSMentionedArr($filesLocalArr) : [];
        $this->reduced = !empty($onlyNSarr) || !empty($skipNSarr);

        return $filesLocalArr;
    }
    
    public function getAllNSInstalledArr() {
        if (!$this->allNSInstalledArr || $this->reduced) {
            $this->getFilesLocalArr();
        }
        return $this->allNSInstalledArr;
    }
}