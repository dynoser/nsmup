<?php
namespace dynoser\nsmupdate;

use dynoser\autoload\AutoLoader;
use dynoser\autoload\AutoLoadSetup;
use dynoser\hashsig\HashSigBase;

class TargetDiff
{    
    public static function targetMapArrToFilesMapArr(array $targetMapsArr, array $onlyNSarr = [], array $skipNSarr = []): array {
        $filesMapArr = []; // [fullFileName] => [['nameSpacesArr'],['hashHexArr']]
        foreach($targetMapsArr as $nsMapKey => $targetMapArr) {
            foreach($targetMapArr as $nameSpace => $dlArr) {
                if ($onlyNSarr && !\in_array($nameSpace, $onlyNSarr)) {
                    continue;
                }
                if ($skipNSarr && \in_array($nameSpace, $skipNSarr)) {
                    continue;
                }
                $hashAlg = $dlArr['*']['hashalg'] ?? 'sha256';

                $targetDir = $dlArr['*']['target'] ?? ' /';
                if (\substr($targetDir, -1) !== '/') {
                    $targetDir = \dirname($targetDir);
                }
                
                foreach($dlArr as $shortName => $remoteArr) {
                    if ($shortName === '*' || !\is_array($remoteArr)) {
                        continue;
                    }
                    $fullFileName = $targetDir . $remoteArr[0];
                    $hashHex = $hashAlg . '#' . $remoteArr[1];
                    $fileLen = $remoteArr[2];
                    if (isset($filesMapArr[$fullFileName][$hashHex][$fileLen])) {
                        $filesMapArr[$fullFileName][$hashHex][$fileLen][] = $nameSpace;
                    } else {
                        $filesMapArr[$fullFileName][$hashHex][$fileLen] = [$nameSpace];
                    }
                }
            }
        }
        return $filesMapArr;
    }
    
    public static function scanIntersectionFilesMapArr(array $filesMapArr): array {
        $localFilesArr = []; // [fullFilePath] => versions
        foreach($filesMapArr as $prefixedFileFull => $hashArr) {
            $fullFilePath = AutoLoader::getPathPrefix($prefixedFileFull);
            if ($fullFilePath && \is_file($fullFilePath)) {
                $localFilesArr[$fullFilePath] = $hashArr;
            }
        }
        return $localFilesArr;
    }
    
    public static function findNSMentionedArr(array $localFilesArr, array $notFoundFilesMapArr = []): array {
        $allNSMentionedArr = []; // [nameSpace] => [fileFull => [hashHex => fileLen]]
        foreach($localFilesArr as $fileFull => $hashArr) {
            foreach($hashArr as $hashHex => $lenNSArr) {
                foreach($lenNSArr as $fileLen => $nameSpaceArr) {
                    foreach($nameSpaceArr as $nameSpace) {
                        $allNSMentionedArr[$nameSpace][$fileFull][$hashHex] = $fileLen;
                    }
                }
            }
        }
        
        // run again for notFound array
        foreach($notFoundFilesMapArr as $fileFull => $hashArr) {
            foreach($hashArr as $hashHex => $lenNSArr) {
                foreach($lenNSArr as $fileLen => $nameSpaceArr) {
                    foreach($nameSpaceArr as $nameSpace) {
                        $allNSMentionedArr[$nameSpace][$fileFull][$hashHex] = $fileLen;
                    }
                }
            }
        }
        
        return $allNSMentionedArr;
    }
    
    public static function scanModifiedFiles(array $filesLocalArr): array {
        $modifiedFiles = [];
        $defaultHashAlg = HashSigBase::DEFAULT_HASH_ALG;
        foreach($filesLocalArr as $fileFull => $pkgArr) {
            foreach($pkgArr as $hashStr => $lenNsArr) {
                $fileDataStr = \file_get_contents($fileFull);
                $i = \strpos($hashStr, '#');
                if (false !== $i) {
                    $hashHex = \substr($hashStr, $i+1);
                    $hashAlg = \substr($hashStr, 0, $i);
                } else {
                    $hashHex = $hashStr;
                    $hashAlg = $defaultHashAlg;
                }
                $chkHashHex = \hash($hashAlg, $fileDataStr);
                if ($chkHashHex !== $hashHex && false !== \strpos($fileDataStr, "\r")) {
                    // try set EOL to canonical
                    $fileDataStr = \strtr($fileDataStr, ["\r" => '']);
                    $chkHashHex = \hash($hashAlg, $fileDataStr);
                }
                if ($chkHashHex !== $hashHex) {
                    $modifiedFiles[$fileFull][$hashStr] = $lenNsArr;
                }
            }
        }
        return $modifiedFiles;
    }
    
    public static function prepareDownLoadFilesArr($allNSModifiedArr) {
        // source format: $allNSModifiedArr[$nameSpace][$fileFull][$hashHex] = $fileLen;
        $downFilesArr = [];
        foreach($allNSModifiedArr as $nameSpace => $modifFilesArr) {
            foreach($modifFilesArr as $fileFull => $aboutFileArr) {
                $downFilesArr[$fileFull] = $aboutFileArr;
            }
        }
        return $downFilesArr;
    }
    
    public static function calcNotFoundArr(array $allNSInstalledArr, array $targetMapsArr) {
        $notFoundFilesMapArr = [];
        foreach($targetMapsArr as $nsMapKey => $targetMapArr) {
            foreach($targetMapArr as $nameSpace => $dlArr) {
                if (!isset($allNSInstalledArr[$nameSpace])) {
                    continue;
                }
                $hashAlg = $dlArr['*']['hashalg'] ?? 'sha256';
                $targetDir = $dlArr['*']['target'] ?? ' /';
                if (\substr($targetDir, -1) !== '/') {
                    $targetDir = \dirname($targetDir);
                }

                foreach($dlArr as $shortName => $remoteArr) {
                    if ($shortName === '*' || !\is_array($remoteArr)) {
                        continue;
                    }
                    $prefixedFileName = $targetDir . $remoteArr[0];
                    $fullFileName = AutoLoader::getPathPrefix($prefixedFileName);
                    if (isset($allNSInstalledArr[$nameSpace][$fullFileName])) {
                        continue;
                    }
                    
                    $hashHex = $hashAlg . '#' . $remoteArr[1];
                    $fileLen = $remoteArr[2];
                    if (isset($notFoundFilesMapArr[$fullFileName][$hashHex][$fileLen])) {
                        $notFoundFilesMapArr[$fullFileName][$hashHex][$fileLen][] = $nameSpace;
                    } else {
                        $notFoundFilesMapArr[$fullFileName][$hashHex][$fileLen] = [$nameSpace];
                    }
                }
            }
        }
        return $notFoundFilesMapArr;
    }
    
    public static function calcUpdatedFilesFromBuild(array $newResults): array {
        $updatedFilesArr = [];
        foreach($newResults as $nsMapKey => $nsMapArr) {
            foreach($nsMapArr as $nameSpace => $filesInPkgArr) {
                foreach($filesInPkgArr as $shortName => $fileArr) {
                    if (!isset($fileArr['fileData']) || !\is_array($fileArr['fileData'])) {
                        continue;
                    }
                    $updatedFilesArr[$nameSpace][$shortName] = $fileArr['fileData'];
                }
            }
        }
        return $updatedFilesArr;
    }
}