<?php

$SERVER_CONFIG = [];

$SERVER_CONFIG = array_merge($SERVER_CONFIG, [
    "serverName"                => "app.stonewood.com.do",
    "host"                      => '192.168.20.221',
    "port"                      => 22,
    "username"                  => 'STONEWOOD/administrator',
    "password"                  => YOUR_PASSWORD_HERE,
    "githubPersonalAccessToken" => YOUR_GITHUB_PERSONAL_ACCESS_TOKEN_HERE,
    "gitHubRepo"                => 'github.com/Stonewood-RD/stonewood-app.git',
    "CONFIG_HOME"               => "C:\\AppStonewood",
]);



$SERVER_CONFIG = array_merge($SERVER_CONFIG, [
    "REPOS_PATH"                => $SERVER_CONFIG["CONFIG_HOME"]."\\Repos\\Production",
    "ENV_FILE_PATH"             => $SERVER_CONFIG["CONFIG_HOME"]."\\Config\\env.php",
    'COMPOSER_AUTH_JSON_PATH'   => $SERVER_CONFIG["CONFIG_HOME"]."\\Config\\Composer\\auth.json",
]);

$SERVER_CONFIG = array_merge($SERVER_CONFIG, [
    "APACHE_CONFIG_PATH"  => 'C:\\StonewoodSitesConf\\'.$SERVER_CONFIG["serverName"].'.conf',
    "certificateFile"     => $SERVER_CONFIG["CONFIG_HOME"]."/Certificate/app.stonewood.com.do-chain.pem",
    "certificateKeyFile"  => $SERVER_CONFIG["CONFIG_HOME"]."/Certificate/app.stonewood.com.do-key.pem",
    "serverAliases" => [
        "appstonewood",
    ],
]);

 
$SERVER_CONFIG["apacheConfig"]["certificateFile"]    = "C:/AppStonewood/Certificate/app.stonewood.com.do-chain.pem";
$SERVER_CONFIG["apacheConfig"]["certificateKeyFile"] = "C:/AppStonewood/Certificate/app.stonewood.com.do-key.pem";


$SERVER_CONFIG["canonicalPath"] = "C:\StonewoodPHP-Production";
