<?php
namespace dynoser\nsmupdate;

class TargetMapBuilder
{
    public bool $echoOn = true;
    
    public function msg($msg) {
        if ($this->echoOn) {
            echo $msg;
        }
    }

    public function __construct(bool $echoOn = true) {
        $this->echoOn = $echoOn;
    }

    public function build(
        array $nsMapArr,
        array $oldTargetMapArr = [],
        int $timeToLivePkgSec = 3600,
        array $onlyNSarr = [],
        array $skipNSarr = [],      
        callable $onEachFile = null,
        string $specialModeStr = ''
    ): ?array
    {
        $newTargetMapArr = [];

        $nsMapLinksArr = TargetMaps::getRemotesFromNSMapArr($nsMapArr);
        $lcnt = \count($nsMapLinksArr);
        if (!$lcnt) {
            $this->msg("No remote links found\n");
            return null;
        }

        if ($specialModeStr) {
            $this->msg($specialModeStr);
        } else {
            $this->msg("($lcnt package links)\n");
        }

        foreach($nsMapLinksArr as $nameSpace => $remoteArr) {
            if (!\is_array($remoteArr)) {
                $this->msg("Package '$nameSpace' skip because remote definition not parsed\n");
                continue;
            }

            $fromURL = $remoteArr['fromURL'];
            if (!empty($oldTargetMapArr[$nameSpace]['*']['checktime']) && ($fromURL === ($oldTargetMapArr[$nameSpace]['*']['fromurl'] ?? ''))) {
                $newTargetMapArr[$nameSpace] = $oldTargetMapArr[$nameSpace];
                $ageTime = time() - $oldTargetMapArr[$nameSpace]['*']['checktime'];
                if (empty($oldTargetMapArr[$nameSpace]['*']['forceupdate']) && ($ageTime < $timeToLivePkgSec)) {
                    continue;
                }
            }

            if ($onlyNSarr && !\in_array($nameSpace, $onlyNSarr)) {
                $specialModeStr || $this->msg("Package '$nameSpace' skip by onlyNSarr\n");
                continue;
            }

            if (\in_array($nameSpace, $skipNSarr)) {
                $specialModeStr || $this->msg("Package '$nameSpace' skip by skipNSarr\n");
                continue;
            }
            
            $this->msg($nameSpace . ' :' . $fromURL . "\n Download... ");

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
                    $sucCnt = \count($dlArr['successArr']);
                    $errCnt = \count($dlArr['errorsArr']);
                    $this->msg(" Success files: " . $sucCnt);
                    if ($onEachFile) {
                        $onePkg = $onEachFile($hs, $remoteArr, $dlArr);
                    } else {
                        $onePkg = $hs->hashSignedArr;
                    }
                    if ($errCnt) {
                        $this->msg(", Error files: $errCnt");
                    } else {
                        $this->msg(", OK");
                        $onePkg['*'] = [
                            'checktime' => time(),
                            'hashalg' => $hs->lastPkgHeaderArr['hashalg'],
                            'filescnt' => $hs->lastPkgHeaderArr['filescnt'],
                            'fromurl' => $remoteArr['fromURL'],
                            'target' => $remoteArr['targetUnpackDir'],
                            'chkfile' => $remoteArr['checkFilesStr'],
                            'ns' => $remoteArr['replaceNameSpace'],
                        ];
                        if ($remoteArr['replaceNameSpace'] === $remoteArr['targetUnpackDir']) {
                            unset($onePkg['*']['target']);
                        }
                        $newTargetMapArr[$nameSpace] = $onePkg; 
                    }
                } else {
                    $this->msg("ERROR download result\n");
                }
                $this->msg("\n");
            } catch (\Throwable $e) {
                $this->msg($e->getMessage());
            } finally {
                $this->msg("\n");
            }
        }
        return $newTargetMapArr;
    }
    
    public static function targetMapMerge(array & $targetMapArr, array $targetMapAddArr): array {
        $onlyAddedNsArr = [];
        foreach($targetMapAddArr as $nameSpace => $pkgArr) {
            $needAdd = true;
            if (\array_key_exists($nameSpace, $targetMapArr)) {
                $curCheckTime = $targetMapArr[$nameSpace]['*']['checktime'] ?? 0;
                $addCheckTime = $pkgArr['*']['checktime'] ?? 0;
                if (!$addCheckTime || $curCheckTime >= $addCheckTime) {
                    $needAdd = false;
                }
            }
            if ($needAdd) {
                $targetMapArr[$nameSpace] = $pkgArr;
                $onlyAddedNsArr[] = $nameSpace;
            }
        }
        return $onlyAddedNsArr;
    }
}