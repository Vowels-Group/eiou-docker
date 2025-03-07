<?php
function debugColors(){
    $debugcolors = array(
        "HEADER" => "\033[95m",
        "OKBLUE" => "\033[94m",
        "OKCYAN" => "\033[96m",
        "OKGREEN" => "\033[92m",
        "WARNING" => "\033[93m",
        "FAIL" => "\033[91m",
        "derp" => "[32m",
        "ENDC" => "\033[0m",
        "BOLD" => "\033[1m",
        "UNDERLINE" => "\033[4m"
    );
    return $debugcolors;    
}

function debugMessage($message){
    $debugcolors = debugColors();
    $message = $message . "\n";
    //colour good and bad
    if (preg_match('/([Ff]ailed)|([Nn]o)/',$message)){
        $message = $debugcolors['FAIL'] . $message . $debugcolors['ENDC'];
    }
    elseif (preg_match('/([Ss]uccess)|([Ii]nserting)/',$message)){
        $message = $debugcolors['OKGREEN'] . $message . $debugcolors['ENDC'];
    }
    //Component clearly outlined
    if(preg_match('/[Cc]omponents/',$message)){
        $message = "\t". $message;
        $message = preg_replace("/[-,] {0,1}/","\n\t\t",$message);
    }
    //tab in Array
    if(preg_match('/Array/',$message)){
        $messageparts = preg_split('/Array/',$message,);
        $messageparts[1] = str_replace("\n","\n\t",$messageparts[1]);
        $message = $messageparts[0] . "\n\tArray" . $messageparts[1];
        if(str_ends_with($message,"\n")){
            $message =  rtrim($message,"\n");
        }
    }
    return array($message, 'ECHO');
}
?>