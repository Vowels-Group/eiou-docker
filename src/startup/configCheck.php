<?php
# Copyright 2025

// Check if userconfig.php already exists and if so if the user keys are available
$run = false;
try{
    // Check if userconfig.php exists
    if(!file_exists('/etc/eiou/userconfig.php')){
        $run = true;
        return;
    }
    require_once '/etc/eiou/userconfig.php';
    // check if the user keys are available
    if(!isset($user["public"])){
        $run = true;
    } 
    return;
} catch(Exception $e){
    return;
}
