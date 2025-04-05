<?php
$passed =false;
try{
    if(!file_exists("/etc/eiou/config.php")){
        return $passed;
    }
    require_once("/etc/eiou/config.php");
    require_once("/etc/eiou/functions/pdo.php");
    $pdo = createPDOConnection();
    $pdo = null;
    $passed = true;
    // return true;
}catch(Exception $e){
    // return false;
}
?>