<?php

$debug        = true;

/*
$rootLevel    = dirname(__FILE__, 4);
$vendorLevel  = dirname(__FILE__, 3);
$autoloadFile = $rootLevel."/vendor/autoload.php";

echo $autoloadFile."\n";

require $autoloadFile;
*/

function findAutoloadFile() {
    $dir = __DIR__;
    while (!file_exists($dir . '/vendor/autoload.php')) {
        $dir = dirname($dir);
        if ($dir === '/') {
            throw new Exception('Failed to find autoload.php. Run Composer install.');
        }
    }
    return $dir . '/vendor/autoload.php';
}

$autoloadPath = getenv('COMPOSER_AUTOLOAD_PATH') ?: findAutoloadFile();

echo "Autoload Path: ".$autoloadPath."\n";

require $autoloadPath;

/*

sudo usermod -aG www-data deployer       # Adds 'deployer'      to the 'www-data' group
sudo usermod -aG www-data palo_deployer  # Adds 'palo_deployer' to the 'www-data' group

Consider Using ACLs for More Granular Control
=============================================
If you need more granular control over permissions, 
consider using Access Control Lists (ACLs).

ACLs allow you to specify more detailed permissions than the basic owner/group/other model. 

Here’s how to set an ACL:

sudo apt-get install acl  # On Debian/Ubuntu systems ---- sudo yum install acl # On CentOS/RedHat systems
setfacl -m u:deployer:rwx /path/to/directory
getfacl /path/to/directory


*/


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
if (isset($SERVER_CONFIG["SSHPrivateKeyFile"]))
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

$host     = $SERVER_CONFIG["host"];
$port     = $SERVER_CONFIG["port"];
$username = $SERVER_CONFIG["username"];

$ssh = new phpseclib3\Net\SSH2($host, $port ?? 22);

switch ($serverAuthenticationMethod) 
{
    case SSHAuthMethod::Password:
        if ($debug)
        {
            echo "Using password\n";
        }
        $ssh->login($SERVER_CONFIG['username'], 
                    $SERVER_CONFIG['password']);
        break;
    case SSHAuthMethod::PublicKey:
        if ($debug)
        {
            echo "Using public key\n";
        }
        $keyBinary = file_get_contents($SERVER_CONFIG["SSHPrivateKeyFile"]);
        $key       = \phpseclib3\Crypt\PublicKeyLoader::load($keyBinary);

        $ssh->login($SERVER_CONFIG['username'], 
                    $key);
        break;
    case SSHAuthMethod::PasswordProtectedPublicKey:
        $filePath = $SERVER_CONFIG["SSHPrivateKeyFile"];
        if ($debug) {
            echo "Using password protected public key\n";
            echo "Key file path: $filePath\n";
        }
        $password  = $SERVER_CONFIG['password'];
        $keyBinary = file_get_contents($filePath);

        if ($debug)
        {
            echo "Key binary: ".$keyBinary."\n";
        }

        /*
            phpseclib
                ...takes in strings---not file paths.
                ...doesn't require a public key. Private keys have the public key 
                embedded within them so phpseclib just extracts it.
                ...can take in pretty much any standardized format, from 
                - PKCS#1 formatted keys,
                - PuTTY keys, 
                - XML Signature keys.
        */

        if ($debug)
        {
            echo "Using load private key\n";
        }

        $key = \phpseclib3\Crypt\PublicKeyLoader::loadPrivateKey($keyBinary, $password);
    
        $ssh->login($SERVER_CONFIG['username'], $key);
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
    throw new Exception("Unable to connect.");
}
else
{
    echo "Connected to $host\n";
}

if (!$ssh->isAuthenticated())
{
    throw new Exception("Authentication failed.");
}
else
{
    echo "Authenticated.\n";
}

checkIfKeysExistOrDie([
    "APACHE_CONFIG_PATH",
    "githubPersonalAccessToken",
    "serverName",
    "gitHubRepo",
    "REPOS_PATH",
    "COMPOSER_AUTH_JSON_PATH",
    "ENV_FILE_PATH",
], $SERVER_CONFIG);



$password                  = $SERVER_CONFIG["password"];
$githubPersonalAccessToken = $SERVER_CONFIG["githubPersonalAccessToken"];
$apacheConfigFilePath      = $SERVER_CONFIG["APACHE_CONFIG_PATH"];
$serverName                = $SERVER_CONFIG["serverName"];
$certificateFile           = $SERVER_CONFIG["apacheConfig"]["certificateFile"]    ?? $SERVER_CONFIG["certificateFile"];
$certificateKeyFile        = $SERVER_CONFIG["apacheConfig"]["certificateKeyFile"] ?? $SERVER_CONFIG["certificateKeyFile"];
$repo                      = $SERVER_CONFIG["gitHubRepo"];
$REPOS_PATH                = $SERVER_CONFIG["REPOS_PATH"];
$COMPOSER_AUTH_JSON_PATH   = $SERVER_CONFIG["COMPOSER_AUTH_JSON_PATH"];
$ENV_FILE_PATH             = $SERVER_CONFIG["ENV_FILE_PATH"];

// $timezone = date_default_timezone_get();
$timezone = "America/Santo_Domingo";
echo "Working with timezone: ".print_r($timezone, true)."\n";
date_default_timezone_set($timezone);


// Define the base path and create a new folder with the current datetime
$dateTime      = new DateTime();
$folderName    = $dateTime->format('Y-m-d_His');

$newFolderPath = null;

if (str_ends_with($REPOS_PATH, "/") || str_ends_with($REPOS_PATH, "\\"))
{
    $newFolderPath = $REPOS_PATH.$folderName;
}   
else
{
    $newFolderPath = $REPOS_PATH.$GTK_DIRECTORY_SEPERATOR.$folderName;
}


$removeRepoDirectoryClosure = function() use ($ssh, $newFolderPath) {
    $ssh->exec('rm "'.$newFolderPath.'"');
};


$cloneCommand = 'cd "'.$newFolderPath.'" && git clone https://'.$githubPersonalAccessToken.':x-oauth-basic@'.$repo.' .';
// Execute the command with $ssh->exec()

$gitCommand = new ScriptCommand($cloneCommand);
$gitCommand->onErrorClosure = $removeRepoDirectoryClosure;
$gitCommand->errorHandler = function ($scriptCommand, $output){};

$documentRoot = $newFolderPath.'/www';

$serverOS = $SERVER_CONFIG["SERVER_OS"] ?? "windows";

$GTK_DIRECTORY_SEPERATOR = "/";

if ($serverOS == "windows")
{
    $GTK_DIRECTORY_SEPERATOR = "\\";
}

$restartCommand             = null;
$makeNewFolderPath               = null;
$copyConfToNewFolderPathEnv = null;
$copyCommand                = null;

switch ($serverOS)
{
    case "windows":
        // $xamppExePath   = 'C:\xampp\xampp-control.exe';
        // $restartCommand = new ScriptCommand('"'.$xamppExePath.'" /restart');
        $restartCommand = new ScriptCommand('C:\xampp\apache\bin\httpd.exe -k restart');
        $makeDirectoryCommand = "mkdir";
        $copyCommand          = "copy";
        break;
    case "linux":
        // sudo setcap 'cap_sys_admin=+ep' /home/palo_deployer/PALO_HOME/Scripts/restart_apache.sh
        // EDITOR=vim visudo
        // -- %www-data ALL=(ALL) NOPASSWD: /usr/local/bin/restart_apache.sh
        // -- %www-data ALL=(ALL) NOPASSWD: /home/palo_deployer/PALO_HOME/Scripts/restart_apache.sh

        $restartCommand = new ScriptCommand('sudo systemctl restart apache2');
        // $restartCommand = new ScriptCommand('sudo systemctl restart apache2');
        $makeDirectoryCommand = "mkdir -p";
        $copyCommand          = "cp";
        break;
}

$makeNewFolderPath          = new ScriptCommand($makeDirectoryCommand." ".escapeshellarg($newFolderPath));

/*

...to get Apache User...do:
ps aux | grep apache
ps aux | grep httpd

$confToSiteFolder = '/etc/apache2/sites-automanaged';
mkdir $confToSiteFolder # feel free to name it what we want...
vim /etc/apache2/apache2.conf        # add `IncludeOptional /etc/apache2/sites-automanaged/*.conf`
chown -R root:www-data $confToSiteFolder
... - 
... - chown -R root:www-data /gtk-conf-managed-sites/
chmod -R 775 $confToSiteFolder
apache2ctl configtest
systemctl restart apache2


Permissions for 775 (OGO)

7 for the owner: The owner has read (4), write (2), and execute (1) permissions. Sum = 7.
7 for the group: Members of the group have read (4), write (2), and execute (1) permissions. Sum = 7.
5 for others: Everyone else has read (4) and execute (1) permissions, but no write permission. Sum = 5.

*/
$copyConfToNewFolderPathEnv = new ScriptCommand($copyCommand.' "'.$apacheConfigFilePath.'" "'.$newFolderPath.'/.secret/apache_server.conf"');


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

$composerInstallCommand = new ScriptCommand('cd '.$newFolderPath.' && composer install');

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


$seedCommand = new ScriptCommand('php "'.$newFolderPath.'/seed/seed.php"');

function getOrCreateDirectoryCommand($directoryPath, $serverOS = "windows")
{
    $makeDirectoryCommand = null;

    switch ($serverOS)
    {
        case "windows":
            $makeDirectoryCommand = "mkdir";
            $command = "if not exist ".$directoryPath." ".$makeDirectoryCommand.' '.$directoryPath;
            break;
        case "linux":
            $makeDirectoryCommand = "mkdir -p";
            $command = $makeDirectoryCommand.' '.$directoryPath;
            break;
    }

    // $command = "[ -d '".$directoryPath."' ] &&  '".$directoryPath." exists!' || $makeDirectoryCommand '".$directoryPath."' && '".$directoryPath." created.'";


    return $command;
}

$makeCanonicalPathCommand = null;
$symLinkCommand = null;

if (isset($SERVER_CONFIG["canonicalPath"]))
{
    
    $makeCanonicalPathCommand = new ScriptCommand(getOrCreateDirectoryCommand($SERVER_CONFIG["canonicalPath"], $serverOS));

    switch ($serverOS)
    {
        case "windows":
            $symLinkCommand = new ScriptCommand('if exist "'.$SERVER_CONFIG["canonicalPath"].'" rmdir /s /q "'.$SERVER_CONFIG["canonicalPath"].'" &&  mklink /D "'.$SERVER_CONFIG["canonicalPath"].'" "'.$newFolderPath.'"');
            break;
        case "linux":
            $symLinkCommand = new ScriptCommand('ln -s "'.$newFolderPath.'" "'.$SERVER_CONFIG["canonicalPath"].'"');
            break;
    }    
}



$commands = [
    new ScriptCommand(getOrCreateDirectoryCommand($REPOS_PATH, $serverOS)),
    $makeNewFolderPath,
    $gitCommand,
    // Need to be executed after because git needs an empty directory

    $copyCommand.' "'.$COMPOSER_AUTH_JSON_PATH.'" "'.$newFolderPath.'"',  
    $makeDirectoryCommand.' "'.$newFolderPath.'/.secret" && '.$copyCommand.' "'.$ENV_FILE_PATH.'" "'.$newFolderPath.'/.secret/env.php"',
    $composerInstallCommand,
    $generateConfCommand,
    $copyConfToNewFolderPathEnv,
    $makeCanonicalPathCommand,
    $symLinkCommand,
    $seedCommand,
    /*
    // Sending the sudo command and password
    $ssh->write("sudo -S $command\n");
    $ssh->write("$password\n");
    */
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

    if ($toExecute)
    {
        $toExecute->executeOrDieOnSSH($ssh);
    }
}

echo "Deployment script executed.\n";
echo "cd ".$newFolderPath."\n";
