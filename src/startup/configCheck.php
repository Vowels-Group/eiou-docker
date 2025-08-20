<?php
# Copyright 2025

// Check if config.php already exists and if so if the user keys are available
$run = false;
try{
    if(!file_exists("/etc/eiou/config.php")){
        $run = true;
        return;
    }
    require_once("/etc/eiou/config.php");
    if(!isset($user["public"])){
        $run = true;
    } 
    return;
} catch(Exception $e){
    $run = true;
    return;
}
