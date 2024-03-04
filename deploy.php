<?php

$autoloadFile = dirname(__FILE__)."/vendor/autoload.php";

echo $autoloadFile."\n";

require $autoloadFile;

use phpseclib3\Net\SSH2;

use function PHPSTORM_META\map;

if ($argc < 2) 
{
    die("Usage: php script.php <server_name>\n");
}

$serverName = $argv[1]; // Get the server name from the command-line argument
$credentialsFilePath = dirname(__FILE__,2) . "/.secret/servers/$serverName.php";

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
    "password",
    "githubPersonalAccessToken",
    "APACHE_CONFIG_PATH",
    "certificateFile",
    "certificateKeyFile",
    "serverName",
    "gitHubRepo",
    "repoToServerPathBase",
    "composerAuthJSONPath",
], $SERVER_CONFIG);


$host                      = $SERVER_CONFIG["host"];
$port                      = $SERVER_CONFIG["port"];
$username                  = $SERVER_CONFIG["username"];
$password                  = $SERVER_CONFIG["password"];
$githubPersonalAccessToken = $SERVER_CONFIG["githubPersonalAccessToken"];
$apacheConfigFilePath      = $SERVER_CONFIG["APACHE_CONFIG_PATH"];
$serverName                = $SERVER_CONFIG["serverName"];
$certificateFile           = $SERVER_CONFIG["certificateFile"];
$certificateKeyFile        = $SERVER_CONFIG["certificateKeyFile"];
$repo                      = $SERVER_CONFIG["gitHubRepo"];
$repoToServerPathBase      = $SERVER_CONFIG["repoToServerPathBase"];
$composerAuthJSONPath      = $SERVER_CONFIG["composerAuthJSONPath"];


echo "Will attempt connection...\n";

$ssh = new SSH2($host, $port ?? 22);

// Authenticate
if (!$ssh->login($username, $password)) 
{
    die('Authentication failed\n');
}


// $timezone = date_default_timezone_get();
$timezone = "America/Santo_Domingo";
echo "Working with timezone: ".print_r($timezone, true)."\n";
date_default_timezone_set($timezone);


// Define the base path and create a new folder with the current datetime
$dateTime = new DateTime();
$folderName = $dateTime->format('Y-m-d_His');
$newFolderPath = $repoToServerPathBase.$folderName;

class ScriptCommand
{
    public $location;
    public $command;
    public $onErrorClosure;
    public $errorHandler;

    public function __construct($command, $location = null, $errorHandler = null)
    {
        $this->command      = $command;
        $this->location     = $location;
        $this->errorHandler = $errorHandler;
    }

    function hasError($output)
    {
        if ($this->errorHandler)
        {
            $errorHandler = $this->errorHandler;
            return $errorHandler($output);
        }
        else
        {
            if (strpos($output, 'error') !== false || strpos($output, 'fatal:') !== false) 
            {
                return "Error executing command: $this->command. Output: $output\n";
            }
        }
        return null;
    }

    public function executeOrDieOnSSH($ssh, $closure = null)
    {
        $finalCommand = $this->command;

        if ($this->location)
        {
            $finalCommand = "cd ".$this->location." && ".$this->command;
        }

        $returnValue = $ssh->exec($finalCommand);

        $errorMessage = $this->hasError($returnValue);
    
        if ($errorMessage)
        {
            if ($closure && is_callable($closure))
            {
                $closure();
            }
            die($errorMessage); 
        }
        else 
        {
            echo $this->command."\n";
        }
    }


}


$removeRepoDirectoryClosure = function() use ($ssh, $newFolderPath) {
    $ssh->exec('rm "'.$newFolderPath.'"');
};

$gitCloneErrorHandler = function ($output) {
    /*
    if (strpos($output, 'fatal:') !== false || empty($output)) {
        die("Error cloning repository. Git output: $output\n");
    }
    */
};



$cloneCommand = "cd \"$newFolderPath\" && git clone https://$githubPersonalAccessToken:x-oauth-basic@$repo .";
// Execute the command with $ssh->exec()

$gitCommand = new ScriptCommand($cloneCommand);
$gitCommand->errorHandler = $gitCloneErrorHandler;
$gitCommand->onErrorClosure = $removeRepoDirectoryClosure;


$documentRoot = $newFolderPath.'\www';

$restartCommand = null;

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
{
    $restartCommand = new ScriptCommand('cd C:\xampp\xampp-control.exe /restart');
} else {
    $restartCommand = new ScriptCommand('systemctl restart apache2');
}



$generateToConfScriptPath  = $newFolderPath.'\deployer\generateApacheConfToPath.php"';

$generateConfString  = '';
$generateConfString .= 'php '.$generateToConfScriptPath;
$generateConfString .= ' '.$documentRoot;
$generateConfString .= ' '.$apacheConfigFilePath;
$generateConfString .= ' '.$serverName;         
$generateConfString .= ' '.$certificateFile;    
$generateConfString .= ' '.$certificateKeyFile;


$commands = [
    new ScriptCommand("mkdir \"$newFolderPath\""),
    $gitCommand,
    "copy \"$composerAuthJSONPath\" \"$newFolderPath\"",  
    "cd \"$newFolderPath\" && composer install",
    "cd \"$newFolderPath\\deployer\" && composer install",
    $generateConfString,
    $restartCommand,
];


$baseScriptCommand = new ScriptCommand("");
$baseScriptCommand->onErrorClosure = $removeRepoDirectoryClosure;

foreach ($commands as $command) 
{
    $toExecute = null;
    if (is_string($commad))
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


/*
use phpseclib3\Net\SFTP;

$localTempConfigPath = tempnam(sys_get_temp_dir(), 'apache_config_');

if (file_put_contents($localTempConfigPath, $apacheConfigContent) === false) {
    die("Failed to write local Apache configuration file.\n");
}

// Assuming $ssh is already authenticated
$sftp = new SFTP($host, $port ?? 22);

if (!$sftp->login($username, $password)) {
    die('SFTP login failed');
}

if (!$sftp->put($apacheConfigFilePath, $localTempConfigPath, SFTP::SOURCE_LOCAL_FILE)) {
    die("Failed to upload Apache configuration file to remote path: $apacheConfigFilePath\n");
}

// Delete the local temporary file after uploading
unlink($localTempConfigPath);
*/
