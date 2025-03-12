<?php
$passed =false;
try{
    if(!file_exists("/var/www/html/eiou/config.php")){
        return $passed;
    }
    require_once("/var/www/html/eiou/config.php");
    require_once("/var/www/html/eiou/functions/pdo.php");
    $pdo = createPDOConnection();
    $pdo = null;
    $passed = true;
    // return true;
}catch(Exception $e){
    // return false;
}
?>