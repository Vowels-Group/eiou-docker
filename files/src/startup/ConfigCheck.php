<?php
# Copyright 2025-2026 Vowels Group, LLC

// Check if userconfig.json already exists and if so if the user keys are available
$run = false;
try{
    // Check if userconfig.json exists
    if(!file_exists('/etc/eiou/config/userconfig.json')){
        $run = true;
        return;
    }
    // check if the user keys are available
    if(!isset(json_decode(file_get_contents('/etc/eiou/config/userconfig.json'),true)["public"])){
        $run = true;
    } 
    return;
} catch(Exception $e){
    error_log('ConfigCheck failed: ' . $e->getMessage());
    return;
}
