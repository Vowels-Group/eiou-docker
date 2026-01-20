<?php
# Copyright 2025-2026 Vowels Group, LLC

// Check if userconfig.json already exists and if so if the user keys are available
$run = false;
try{
    // Check if userconfig.json exists
    if(!file_exists('/etc/eiou/userconfig.json')){
        $run = true;
        return;
    }
    // check if the user keys are available
    if(!isset(json_decode(file_get_contents('/etc/eiou/userconfig.json'),true)["public"])){
        $run = true;
    } 
    return;
} catch(Exception $e){
    return;
}
