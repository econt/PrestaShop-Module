<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('memory_limit', 5 * 1024 . 'M');
ini_set('max_execution_time', (60 * 5) * 60);

header('Content-type: text/plain; charset=utf-8');
//header('Content-type: text/html; charset=utf-8');

define('CURRENT_DIR', __DIR__);
define('PRESTASHOP_MODULES_DIR', CURRENT_DIR . DIRECTORY_SEPARATOR . 'modules');

define('SHIPPING_MODULE_NAME', 'econtdelivery');
define('SHIPPING_MODULE_DIR', PRESTASHOP_MODULES_DIR . DIRECTORY_SEPARATOR . SHIPPING_MODULE_NAME);

define('PAYMENT_MODULE_NAME', 'econtdeliverypayment');
define('PAYMENT_MODULE_DIR', SHIPPING_MODULE_DIR . DIRECTORY_SEPARATOR . PAYMENT_MODULE_NAME);

function recursiveRemoveDir($dirName) {
    if (!is_dir($dirName)) return false;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirName, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $dirContent) {
        ($dirContent->isDir() && !$dirContent->isLink()) ? @rmdir($dirContent->getPathname()) : @unlink($dirContent->getPathname());
    }

    return @rmdir($dirName);
}
function recursiveCopyDir($sourceDir, $destinationDir) {
    if (!is_dir($sourceDir)) {
        return false;
    }
    
    $dir = opendir($sourceDir);
    if ($dir === false) {
        return false;
    }
    
    @mkdir($destinationDir);
    while (($file = readdir($dir))) {
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($sourceDir . DIRECTORY_SEPARATOR . $file) ) recursiveCopyDir($sourceDir . DIRECTORY_SEPARATOR . $file, $destinationDir . DIRECTORY_SEPARATOR . $file);
            else @copy($sourceDir . DIRECTORY_SEPARATOR . $file, $destinationDir . DIRECTORY_SEPARATOR . $file);
        }
    }
    closedir($dir);
    return true;
}
function createModuleZip($moduleName) {
    $moduleZipFile = CURRENT_DIR . DIRECTORY_SEPARATOR . MODULE_ZIP_FILE_NAME;
    @unlink($moduleZipFile);

    $zip = new ZipArchive();
    if ($zip->open($moduleZipFile, ZipArchive::CREATE) !== true) {
        die('error 1');
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PRESTASHOP_MODULES_DIR . DIRECTORY_SEPARATOR . $moduleName, FilesystemIterator::SKIP_DOTS)) as $file) {
        $zip->addFile($file->getPathname(), ltrim(str_replace(PRESTASHOP_MODULES_DIR, '', $file->getPathname()), '\\/'));
    }

    $zip->setArchiveComment('Version: ' . MODULE_VERSION);
    $zip->close();

    return $moduleZipFile;
}

define('MODULE_VERSION', '2.0.3');
define('MODULE_ZIP_FILE_NAME', 'EcontDelivery-PrestaShop-Module.zip');

foreach ([
    SHIPPING_MODULE_DIR . DIRECTORY_SEPARATOR . SHIPPING_MODULE_NAME . '.php',
    PAYMENT_MODULE_DIR .  DIRECTORY_SEPARATOR . PAYMENT_MODULE_NAME . '.php'
] as $versionFile) {
    if (file_exists($versionFile)) {
        $content = file_get_contents($versionFile);
        $updatedContent = preg_replace('/\$this->version = \'(.*)\'; \/\/ change from zip generator/i', '$this->version = \'' . MODULE_VERSION. '\'; // change from zip generator', $content, 1);
        file_put_contents($versionFile, $updatedContent);
    }
}

@unlink(SHIPPING_MODULE_DIR . DIRECTORY_SEPARATOR . 'config.xml');
@unlink(PAYMENT_MODULE_DIR . DIRECTORY_SEPARATOR . 'config.xml');

createModuleZip(SHIPPING_MODULE_NAME);

