<?php
function smtpCheckEmail($emailToCheck) {
    $domain = substr(strrchr($emailToCheck, "@"), 1);
    $publicDomains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'my.jru.edu'];

    // Skip SMTP probe for trusted domains
    if (in_array(strtolower($domain), $publicDomains)) {
        return true;
    }

    if (!getmxrr($domain, $mxHosts)) return false;
    $mxHost = $mxHosts[0];
    $from = 'check@yourdomain.com';

    $connect = @fsockopen($mxHost, 25, $errno, $errstr, 10);
    if (!$connect) return false;

    $cmds = [
        "HELO yourdomain.com",
        "MAIL FROM:<$from>",
        "RCPT TO:<$emailToCheck>",
        "QUIT"
    ];

    foreach ($cmds as $cmd) {
        fwrite($connect, $cmd . "\r\n");
        $response = fgets($connect, 1024);
        if (strpos($cmd, 'RCPT TO') !== false && strpos($response, '250') === false) {
            fclose($connect);
            return false;
        }
    }

    fclose($connect);
    return true;
}
