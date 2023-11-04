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
        int $timeToLivePkgSec = 3600
    ): ?array
    {
        $newTargetMapArr = [];

        $nsMapLinksArr = TargetMaps::getRemotesFromNSMapArr($nsMapArr);
        $lcnt = \count($nsMapLinksArr);
        if (!$lcnt) {
            $this->msg("No links found\n");
            return null;
        }

        $this->msg("Verifycation $lcnt links:\n");

        foreach($nsMapLinksArr as $nameSpace => $remoteArr) {
            $fromURL = $remoteArr['fromURL'];
            if (!empty($oldTargetMapArr[$nameSpace]['*']['checktime']) && ($fromURL === $oldTargetMapArr[$nameSpace]['*']['fromURL'] ?? '')) {
                $newTargetMapArr[$nameSpace] = $oldTargetMapArr[$nameSpace];
                $ageTime = time() - $oldTargetMapArr[$nameSpace]['*']['checktime'];
                if ($ageTime < $timeToLivePkgSec) {
                    continue;
                }
            }
            
            $this->msg($nameSpace . ': :' . $fromURL . "\n Download... ");

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
                    $this->msg(" Success files: " . \count($dlArr['successArr']));
                    $errCnt = \count($dlArr['errorsArr']);
                    if ($errCnt) {
                        $this->msg(", Error files: $errCnt");
                    } else {
                        $this->msg(", OK");
                        $onePkg = $hs->hashSignedArr;
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
}