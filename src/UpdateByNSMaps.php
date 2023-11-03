<?php
namespace dynoser\nsmap;

use dynoser\nsmap\TargetDiff;

class UpdateByNSMaps
{
    public $echoOn = true;
    
    public function __construct(bool $autoRun = true)
    {        
        if ($autoRun) {
            $this->update();
        }
    }
    
    public function update(array $onlyNSarr = [], array $skipNSarr = []) {
        $tmObj = new TargetMaps();

        $remoteNSMapURLs = $tmObj->getRemoteNSMapURLs();
        $loadedNSMapsArr = $tmObj->downLoadNSMaps($remoteNSMapURLs, true);
        $targetMapsArr = $tmObj->buildTargetMaps($loadedNSMapsArr, true, $onlyNSarr, $skipNSarr);
        
        $filesMapArr = TargetDiff::targetMapArrToFilesMapArr($targetMapsArr);
        $filesLocalArr = TargetDiff::scanIntersectionFilesMapArr($filesMapArr);
        $allNSInstalledArr = TargetDiff::findNSMentionedArr($filesLocalArr);
        $modifiedFilesArr = TargetDiff::scanModifiedFiles($filesLocalArr);
        if ($modifiedFilesArr) {
            $allNSModifiedArr = TargetDiff::findNSMentionedArr($modifiedFilesArr);
            $downFilesArr = TargetDiff::prepareDownLoadFilesArr($allNSModifiedArr);
            $fnAdder = function($hs, $remoteArr, $dlArr) {
                $arr = $hs->hashSignedArr;
                foreach($dlArr['successArr'] as $shortFile => $fileData) {
                    if (isset($arr[$shortFile])) {
                        $arr[$shortFile]['fileData'] = $fileData;
                    }
                }
                return $arr;
            };
            $newResults = $tmObj->buildTargetMaps($loadedNSMapsArr, false, \array_keys($allNSModifiedArr), $skipNSarr, $fnAdder, $downFilesArr);
        }
    }
}