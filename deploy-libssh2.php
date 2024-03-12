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
        $keyBinary = file_get_contents($SERVER_CONFIG["certificateKeyFile"]);
        $key       = PublicKeyLoader::load($keyBinary);

        $ssh->login($SERVER_CONFIG['username'], 
                    $key);
        break;
    case SSHAuthMethod::PasswordProtectedPublicKey:
        $password  = $SERVER_CONFIG['password'];
        $keyBinary = file_get_contents($SERVER_CONFIG["certificateKeyFile"], $password);
        $key       = PublicKeyLoader::load($keyBinary);

        $ssh->ssh2_auth_pubkey_file(
            $ssh,
            $SERVER_CONFIG['username'], 
                    $key);
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
    "repoToServerPathBase",
    "composerAuthJSONPath",
], $SERVER_CONFIG);



$password                  = $SERVER_CONFIG["password"];
$githubPersonalAccessToken = $SERVER_CONFIG["githubPersonalAccessToken"];
$apacheConfigFilePath      = $SERVER_CONFIG["APACHE_CONFIG_PATH"];
$serverName                = $SERVER_CONFIG["serverName"];
$certificateFile           = $SERVER_CONFIG["certificateFile"];
$certificateKeyFile        = $SERVER_CONFIG["certificateKeyFile"];
$repo                      = $SERVER_CONFIG["gitHubRepo"];
$repoToServerPathBase      = $SERVER_CONFIG["repoToServerPathBase"];
$composerAuthJSONPath      = $SERVER_CONFIG["composerAuthJSONPath"];

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

    function hasError($ssh, $output)
    {
        $debug = true;

        $exitStatus = $ssh->getExitStatus();

        if ($debug)
        {
            echo "Exit status: $exitStatus\n";
            error_log("Exit status: $exitStatus\n");
        }

        if ($exitStatus)
        {
            $stdErr = $ssh->getStdError();
            return "Error executing command: $this->command. Exit status: $exitStatus. Output: $output. Std-Err: $stdErr \n";
        }

        if ($this->errorHandler)
        {
            $errorHandler = $this->errorHandler;
            return $errorHandler($this, $output);
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
        $debug = true;

        $startedQuiet = $ssh->isQuietModeEnabled();

        if ($startedQuiet)
        {
            if ($debug)
            {
                echo "Quiet mode is enabled. Disabling...\n";
                error_log("Quiet mode is enabled. Disabling...");
            }
            $ssh->disableQuietMode();
        }
        else
        {
            if ($debug)
            {
                echo "Quiet mode is disabled.\n";
                error_log("Quiet mode is disabled.");
            }
        }

        $finalCommand = $this->command;

        if ($this->location)
        {
            $finalCommand = "cd ".$this->location." && ".$this->command;
        }

        if ($debug)
        {
            echo "Executing command: $finalCommand\n";
            error_log("Executing command: $finalCommand");
        }

        $returnValue = $ssh->exec($finalCommand);

        if ($debug)
        {
            echo "Output: $returnValue\n";
            error_log("Output: $returnValue");
        }

        $errorMessage = $this->hasError($ssh, $returnValue);

        if ($startedQuiet)
        {
            $ssh->enableQuietMode();
        }
    
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
        return "Error executing command: $scriptCommand->command. Output: $output\n";
    }
    else
    {
        return null;
    }
};

$commands = [
    new ScriptCommand("mkdir -p ".escapeshellarg($newFolderPath)),
    $gitCommand,
    "copy \"$composerAuthJSONPath\" \"$newFolderPath\"",  
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
