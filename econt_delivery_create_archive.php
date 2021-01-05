<?php

define('CURRENT_DIR', __DIR__);
define('PRESTASHOP_MODULES_DIR', CURRENT_DIR . DIRECTORY_SEPARATOR . 'modules');

define('SHIPPING_MODULE_NAME', 'econtdelivery');
define('SHIPPING_MODULE_DIR', PRESTASHOP_MODULES_DIR . DIRECTORY_SEPARATOR . SHIPPING_MODULE_NAME);

define('PAYMENT_MODULE_NAME', 'econtdeliverypayment');
define('PAYMENT_MODULE_DIR', PRESTASHOP_MODULES_DIR . DIRECTORY_SEPARATOR . PAYMENT_MODULE_NAME);

function recursiveRemoveDir($dirName) {
    if (!is_dir($dirName)) return false;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirName, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $dirContent) {
        ($dirContent->isDir() && !$dirContent->isLink()) ? @rmdir($dirContent->getPathname()) : @unlink($dirContent->getPathname());
    }

    return @rmdir($dirName);
}
function createModuleZip($moduleName) {
    $moduleZipFile = CURRENT_DIR . DIRECTORY_SEPARATOR . $moduleName . '.zip';
    @unlink($moduleZipFile);

    $zip = new ZipArchive();
    if ($zip->open($moduleZipFile, ZipArchive::CREATE) !== true) die('error 1');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PRESTASHOP_MODULES_DIR . DIRECTORY_SEPARATOR . $moduleName, FilesystemIterator::SKIP_DOTS)) as $file) {
        $zip->addFile($file, DIRECTORY_SEPARATOR . str_replace(PRESTASHOP_MODULES_DIR, '', $file));
    }
    $zip->close();

    return $moduleZipFile;
}
function recursiveCopyDir($sourceDir, $destinationDir) {
    $dir = opendir($sourceDir);
    @mkdir($destinationDir);
    while (($file = readdir($dir))) {
        if (( $file != '.' ) && ( $file != '..' )) {
            if ( is_dir($sourceDir . DIRECTORY_SEPARATOR . $file) ) recursiveCopyDir($sourceDir . DIRECTORY_SEPARATOR . $file, $destinationDir . DIRECTORY_SEPARATOR . $file);
            else @copy($sourceDir . DIRECTORY_SEPARATOR . $file, $destinationDir . DIRECTORY_SEPARATOR . $file);
        }
    }
    closedir($dir);
}

@unlink(SHIPPING_MODULE_DIR . DIRECTORY_SEPARATOR . 'config.xml');
@unlink(PAYMENT_MODULE_DIR . DIRECTORY_SEPARATOR . 'config.xml');
recursiveRemoveDir(SHIPPING_MODULE_DIR . DIRECTORY_SEPARATOR . PAYMENT_MODULE_NAME);
recursiveCopyDir(PAYMENT_MODULE_DIR, SHIPPING_MODULE_DIR . DIRECTORY_SEPARATOR . PAYMENT_MODULE_NAME);
createModuleZip(SHIPPING_MODULE_NAME);

//if (false) {
//    $paymentModuleZipFile = createModuleZip(PAYMENT_MODULE);
//    $paymentModuleZipFileDestination = PRESTASHOP_MODULES_DIR . DIRECTORY_SEPARATOR . SHIPPING_MODULE . DIRECTORY_SEPARATOR . basename($paymentModuleZipFile);
//    @unlink($paymentModuleZipFileDestination);
//    @copy($paymentModuleZipFile, $paymentModuleZipFileDestination);
//    @unlink($paymentModuleZipFile);
//    echo createModuleZip(SHIPPING_MODULE);
//}