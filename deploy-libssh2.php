<?php

$rootLevel    = dirname(__FILE__, 4);
$vendorLevel  = dirname(__FILE__, 3);
$autoloadFile = $rootLevel."/vendor/autoload.php";

echo $autoloadFile."\n";

require $autoloadFile;

if ($argc < 2) 
{
    die("Usage: php script.php <server_name>\n");
}

$serverName = $argv[1]; // Get the server name from the command-line argument
$credentialsFilePath = $rootLevel."/.secret/servers/$serverName.php";

echo "Looking for credentials file path at: ".$credentialsFilePath."\n";

// Check if the credentials file exists
/** Example Credentials File - @<ROOT_DIR>/.secret/your_server_name.php
[
    "host"       => 'your_windows_server_ip',
    "port"       => 22,
    "username"   => 'your_username',
    "password"   => 'your_password',
    "localFile"  => '/path/to/local/file.txt',
    "remoteFile" => '/path/to/remote/file.txt',
]
 */
if (!file_exists($credentialsFilePath)) {
    die("Error: Credentials file for $serverName not found.\n");
}

// Load the credentials
require $credentialsFilePath;

function keysExist(array $keys, array $array): bool {
    return !array_diff_key(array_flip($keys), $array);
}

function checkIfKeysExistOrDie($requiredKeys, $config) {
    $missingKeys = [];

    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $config)) {
            $missingKeys[] = $key;
        }
    }

    if (!empty($missingKeys)) {
        $missingKeysList = implode(', ', $missingKeys);
        die("Error: Missing configuration keys: $missingKeysList in \$serverName.php.\n");
    }

    return true;
}

checkIfKeysExistOrDie([
    "host", 
    "port", 
    "username",
], $SERVER_CONFIG);

$hasServerAuthenticationMethod = false;

enum SSHAuthMethod: string
{
    case None = 'none';
    case Password = 'password';
    case PublicKey = 'public-key';
    case PasswordProtectedPublicKey = 'password-protected-public-key';
    case KeyboardInteractive = 'keyboard-interactive';
    case Agent = 'agent';
}

$serverAuthenticationMethod = SSHAuthMethod::None; 
    
// https://phpseclib.com/docs/auth
// https://phpseclib.com/docs/why#phpseclib-vs-openssl

if (isset($SEVER_CONFIG["SSHCertificateFile"]))
{
    $serverAuthenticationMethod = SSHAuthMethod::PublicKey;
    
    if (isset($SERVER_CONFIG["password"]))
    {
        $serverAuthenticationMethod = SSHAuthMethod::PasswordProtectedPublicKey;
    }
}
else if (isset($SERVER_CONFIG["password"]))
{
    $serverAuthenticationMethod = SSHAuthMethod::Password;
}

echo "Will attempt connection...\n";

$host                      = $SERVER_CONFIG["host"];
$port                      = $SERVER_CONFIG["port"];
$username                  = $SERVER_CONFIG["username"];

$ssh = ssh2_connect($host, $port ?? 22);

switch ($serverAuthenticationMethod) 
{
    case SSHAuthMethod::Password:
        ssh2_auth_password($ssh,
            $SERVER_CONFIG['username'], 
            $SERVER_CONFIG['password']);
        break;
    case SSHAuthMethod::PublicKey:
        ssh2_auth_pubkey_file($ssh,
            $SERVER_CONFIG['username'], 
            $SERVER_CONFIG['SSHPublicKeyFile'],
            $SERVER_CONFIG['SSHPrivateKeyFile']);
        break;
    case SSHAuthMethod::PasswordProtectedPublicKey:
        ssh2_auth_pubkey_file($ssh,
            $SERVER_CONFIG['username'], 
            $SERVER_CONFIG['SSHPublicKeyFile'],
            $SERVER_CONFIG['SSHPrivateKeyFile'],
            $password);
        break;
        break;
    case SSHAuthMethod::KeyboardInteractive:
        throw new Exception("TODO - Keyboard Interactive");
        break;
    case SSHAuthMethod::Agent:
        // Handle authentication using SSH agent
        // $agent = new \phpseclib3\System\SSH\Agent();
        // $ssh->login('username', $agent);
        throw new Exception("TODO - SSH Agent");
        break;
}


if (!$ssh->isConnected()) 
{
    throw new Exception("Authentication failed or unable to connect.");
}
else
{
    echo "Connected to $host\n";
}

checkIfKeysExistOrDie([
    "APACHE_CONFIG_PATH",
    "githubPersonalAccessToken",
    "serverName",
    "gitHubRepo",
    "REPOS_PATH",
    "COMPOSER_AUTH_JSON_PATH",
], $SERVER_CONFIG);



$password                  = $SERVER_CONFIG["password"];
$githubPersonalAccessToken = $SERVER_CONFIG["githubPersonalAccessToken"];
$apacheConfigFilePath      = $SERVER_CONFIG["APACHE_CONFIG_PATH"];
$serverName                = $SERVER_CONFIG["serverName"];
$certificateFile           = $SERVER_CONFIG["apacheConfig"]["certificateFile"];
$certificateKeyFile        = $SERVER_CONFIG["certificateKeyFile"];
$repo                      = $SERVER_CONFIG["gitHubRepo"];
$REPOS_PATH      = $SERVER_CONFIG["REPOS_PATH"];
$COMPOSER_AUTH_JSON_PATH      = $SERVER_CONFIG["COMPOSER_AUTH_JSON_PATH"];

// $timezone = date_default_timezone_get();
$timezone = "America/Santo_Domingo";
echo "Working with timezone: ".print_r($timezone, true)."\n";
date_default_timezone_set($timezone);


// Define the base path and create a new folder with the current datetime
$dateTime = new DateTime();
$folderName = $dateTime->format('Y-m-d_His');
$newFolderPath = $REPOS_PATH.$folderName;

$removeRepoDirectoryClosure = function() use ($ssh, $newFolderPath) {
    $ssh->exec('rm "'.$newFolderPath.'"');
};


$cloneCommand = "cd \"$newFolderPath\" && git clone https://$githubPersonalAccessToken:x-oauth-basic@$repo .";
// Execute the command with $ssh->exec()

$gitCommand = new ScriptCommand($cloneCommand);
$gitCommand->onErrorClosure = $removeRepoDirectoryClosure;
$gitCommand->errorHandler = function ($scriptCommand, $output){};


$documentRoot = $newFolderPath.'\www';

$restartCommand = null;

$serverOS = $SERVER_CONFIG["SERVER_OS"] ?? "windows";

switch ($serverOS)
{
    case "windows":
        $xamppExePath = 'C:\xampp\xampp-control.exe';
        $restartCommand = new ScriptCommand('cd "'.$xamppExePath.'" /restart');
        break;
    case "linux":
        $restartCommand = new ScriptCommand('systemctl restart apache2');
        break;
}

$vendorName  = "mucholove";
$libraryName = "deployer";

$generateToConfScriptPath  = $newFolderPath.'/vendor/'.$vendorName.'/'.$libraryName.'/generateApacheConfToPath.php';

$generateConfString  = '';
$generateConfString .= 'php "'.$generateToConfScriptPath.'"';
$generateConfString .= ' "'.$documentRoot.'"';
$generateConfString .= ' "'.$apacheConfigFilePath.'"';
$generateConfString .= ' "'.$serverName.'"';         
$generateConfString .= ' "'.$certificateFile.'"';    
$generateConfString .= ' "'.$certificateKeyFile.'"';


$generateConfCommand = new ScriptCommand($generateConfString);
$generateConfCommand->onErrorClosure = $removeRepoDirectoryClosure;

$composerInstallCommand = new ScriptCommand("cd \"$newFolderPath\" && composer install");
$composerInstallCommand->errorHandler = function ($scriptCommand, $output) {
    $tests = [
        strpos($output, 'error') !== false,
        strpos($output, 'repository does not exist') !== false,
    ];

    $hasError = !empty(array_filter($tests));

    if ($hasError)
    {
        return "Error executing command - BEGIN:: $scriptCommand->command ::END - Output: $output\n";
    }
    else
    {
        return null;
    }
};

$commands = [
    new ScriptCommand("mkdir -p ".escapeshellarg($newFolderPath)),
    $gitCommand,
    "copy \"$COMPOSER_AUTH_JSON_PATH\" \"$newFolderPath\"",  
    $composerInstallCommand,
    $generateConfCommand,
    $restartCommand,
];


$baseScriptCommand = new ScriptCommand("");
$baseScriptCommand->onErrorClosure = $removeRepoDirectoryClosure;

foreach ($commands as $command) 
{
    $toExecute = null;
    if (is_string($command))
    {
        $baseScriptCommand->command = $command; 
        $toExecute = $baseScriptCommand;
    }
    else
    {
        $toExecute = $command;
    }
    $toExecute->executeOrDieOnSSH($ssh);
}

echo "Deployment script executed.\n";
