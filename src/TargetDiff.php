<?php
namespace dynoser\nsmap;

use dynoser\autoload\AutoLoader;
use dynoser\autoload\AutoLoadSetup;
use dynoser\hashsig\HashSigBase;

class TargetDiff
{    
    public static function targetMapArrToFilesMapArr(array $targetMapsArr): array {
        $filesMapArr = []; // [fullFileName] => [['nameSpacesArr'],['hashHexArr']]
        foreach($targetMapsArr as $nsMapKey => $targetMapArr) {
            foreach($targetMapArr as $nameSpace => $dlArr) {
                $hashAlg = $dlArr['*']['hashalg'] ?? 'sha256';
                foreach($dlArr as $shortName => $remoteArr) {
                    if ($shortName === '*' || !\is_array($remoteArr)) {
                        continue;
                    }
                    $fullFileName = $remoteArr[0];
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
        foreach($filesMapArr as $fileFull => $hashArr) {
            $fullFilePath = AutoLoader::getPathPrefix($fileFull);
            if ($fullFilePath && \is_file($fullFilePath)) {
                $localFilesArr[$fullFilePath] = $hashArr;
            }
        }
        return $localFilesArr;
    }
    
    public static function findNSMentionedArr($localFilesArr) {
        $allNSInstalledArr = []; // [nameSpace] => [fileFull => [hashHex => fileLen]]
        foreach($localFilesArr as $fileFull => $hashArr) {
            foreach($hashArr as $hashHex => $lenNSArr) {
                foreach($lenNSArr as $fileLen => $nameSpaceArr) {
                    foreach($nameSpaceArr as $nameSpace) {
                        $allNSInstalledArr[$nameSpace][$fileFull][$hashHex] = $fileLen;
                    }
                }
            }
        }
        return $allNSInstalledArr;
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
}