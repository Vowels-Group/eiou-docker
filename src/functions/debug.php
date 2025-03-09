<?php
function debugColors(){
    $debugcolors = array(
        "HEADER" => "\033[95m",
        "OKBLUE" => "\033[94m",
        "OKCYAN" => "\033[96m",
        "OKGREEN" => "\033[92m",
        "WARNING" => "\033[93m",
        "FAIL" => "\033[91m",
        "ENDC" => "\033[0m",
        "BOLD" => "\033[1m",
        "UNDERLINE" => "\033[4m",
        "DEFAULT" => "\033[0m"
    );
    return $debugcolors;    
}

function debugMessage($message,$debugTrace,$databaseDebug = false){
    $debugcolors = debugColors();
    $message = rtrim($message);
    if (preg_match('/\([A-Za-z]+\)/',$message)){
        //GET WHAT REMOVED
        preg_match('/\([A-Za-z]+\)/',$message,$matches);
        $messages = preg_split('/\([A-Za-z]+\)/',$message);
        $message = $messages[0];
    }
    if(isset($debugTrace[1]["function"])){
        $functionName = $debugTrace[1]["function"]; 
    }
    else{
        $functionName = $debugTrace[0]["function"]; 
    }
    $paddedFunctionName = str_pad("(" . $functionName .")",25, " ", STR_PAD_RIGHT);
    $message = $paddedFunctionName . " -> ".$message . "\n";
    //colour good and bad
    if (preg_match('/([Ff]ailed)|([Nn]o)/',$message)){
        $message = $debugcolors['FAIL'] . $message . $debugcolors['DEFAULT'];
    }
    elseif (preg_match('/([Ss]uccess)|([Ii]nsert)|([Acc]ept)/',$message)){
        $message = $debugcolors['OKGREEN'] . $message . $debugcolors['DEFAULT'];
    }
    //Component clearly outlined
    if(preg_match('/[Cc]omponents/',$message)){
        $message = preg_replace("/, {0,1}/","\n\t\t",$message);
    }
    //tab in Array
    if(preg_match('/Array/',$message)){
        $messageparts = preg_split('/Array/',$message,);
        $messageparts[1] = str_replace("\n","\n\t",$messageparts[1]);
        $message = $messageparts[0] . "\n\tArray" . $messageparts[1];
        $message = rtrim($message);
        $message = $message . "\n";
    }
    
    if(isset($messages[1])){
        if(preg_match('/\{/',$messages[1])){
            $messageparts = preg_split('/\{/',$messages[1]);
            if (preg_match('/([Ww]arning)/',$message)){
                $messages[1] = "\n" . $debugcolors['WARNING'] . $messageparts[0] . "\t{" . $messageparts[1] . $debugcolors['DEFAULT'] ;
            }
            elseif (preg_match('/([Ss]uccess)/',$message)){
                $messages[1] = "\n" . $debugcolors['OKGREEN'] . $messageparts[0] . "\t{" . $messageparts[1] . $debugcolors['DEFAULT'] ;
            }
            $messages[1] = rtrim($messages[1]);
        }
        $message = $message . $matches[0] .$messages[1] ."\n";
    }
    else{
        if(preg_match('/\{/',$message)){
            $messageparts = preg_split('/\{/',$message);
            if (preg_match('/([Ww]arning)/',$message)){
                $message =  $messageparts[0] . "\n\t" .$debugcolors['WARNING'] . "{" . $messageparts[1] . $debugcolors['DEFAULT'] ;
            }
            elseif (preg_match('/([Ss]uccess)/',$message)){
                $message =  $messageparts[0] . "\n\t" . $debugcolors['OKGREEN'] . "{" . $messageparts[1] . $debugcolors['DEFAULT'] ;
            }
            $message = rtrim($message);
        }
    }
    $message = "\n" . $message . "\n";
    return $message;
}
?>