<?php

require_once("find_autoload_file.php");

$autoloadPath = getenv('COMPOSER_AUTOLOAD_PATH') ?: findAutoloadFile();

echo "Autoload Path: ".$autoloadPath."\n";

require $autoloadPath;

/* <?php
// /.secret/servers/setup/server1.php

return [
    'AppName' => 'palo1',
    'DeployerUserName' => 'palo1_deployer',
    'ApacheUserGroup' => 'www-data',
    'BashLocation' => '/usr/bin/bash',
    'ConfFolderForSites' => '/gtk-www/conf',
    'AppPath' => '/Apps/palo1',
    'SFTPServer' => 'your-sftp-server.com',
    'SFTPUsername' => 'sftp_username',
    'PrivateKeyPath' => '/.secret/ssh/private_key',
    'PublicKeyPath' => '/.secret/ssh/public_key',
    // Additional configurations as needed
];
*/


use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

// Argument Parsing (e.g., 'php script.php server1')
$serverName = $argv[1] ?? exit("Server name argument required.\n");

// Configuration Loading
$configFile = __DIR__ . "/.secret/servers/setup/{$serverName}.php";
if (!file_exists($configFile)) {
    exit("Configuration file does not exist for the specified server.\n");
}
$config = require $configFile;

// Required Configurations Check
$requiredConfigs = [
    'AppName', 'DeployerUserName', 'ApacheUserGroup', 'BashLocation', 'AppPath',
    'SFTPServer', 'SFTPUsername', 'PrivateKeyPath', 'PublicKeyPath'
];
foreach ($requiredConfigs as $req) {
    if (!isset($config[$req])) {
        exit("Configuration error: '$req' is missing in the configuration file.\n");
    }
}

// Extract configurations
extract($config);

// SFTP Connection with SSH Key
$sftp = new SFTP($SFTPServer);
$privateKey = PublicKeyLoader::load(file_get_contents($PrivateKeyPath));

if (!$sftp->login($SFTPUsername, $privateKey)) {
    exit('SFTP login failed with SSH key');
}

// Directory and permission setup commands
$commands = [
    "useradd -m -s $BashLocation -G $ApacheUserGroup $DeployerUserName",
    "usermod -aG $ApacheUserGroup $DeployerUserName",
    "mkdir -p $AppPath $AppDBPath $CanonicalPath $ReposPath $ScriptsPath $ConfigPath $ComposerPath",
    "chown -R $DeployerUserName:$ApacheUserGroup $AppPath",
    "chmod -R 774 $AppPath",
    "echo '$DeployerUserName ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart apache2' >> /etc/sudoers"
];

foreach ($commands as $command) {
    exec($command, $output, $status);
    echo $status === 0 ? "Executed: $command\n" : "Failed: $command\n";
}

// Function for file upload via SFTP
function uploadFileTo($sftp, $localFilePath, $remoteFilePath)
{
    if ($sftp->put($remoteFilePath, $localFilePath, SFTP::SOURCE_LOCAL_FILE)) {
        echo "Upload successful!\n";
    } else {
        echo "Upload failed\n";
    }
}

// Example usage of file upload
uploadFileTo($sftp, $LocalComposerAuthJSONFile, $ComposerAuthJSONPath);


/*

root@hit-palo-production:/home/palo_deployer/PALO_HOME/Config# certbot certonly --apache
Saving debug log to /var/log/letsencrypt/letsencrypt.log

Which names would you like to activate HTTPS for?
- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
1: fossil.palo.do
2: hit.palo.do
3: staging.hit.palo.do
4: staging.palo.do
- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
Select the appropriate numbers separated by commas and/or spaces, or leave input
blank to select all options shown (Enter 'c' to cancel): 3
- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
Certificate is saved at: /etc/letsencrypt/live/staging.hit.palo.do/fullchain.pem
Key is saved at:         /etc/letsencrypt/live/staging.hit.palo.do/privkey.pem
This certificate expires on 2024-07-31.
These files will be updated when the certificate renews.
Certbot has set up a scheduled task to automatically renew this certificate in the background.

- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
If you like Certbot, please consider supporting our work by:
 * Donating to ISRG / Let's Encrypt:   https://letsencrypt.org/donate
 * Donating to EFF:                    https://eff.org/donate-le
- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

*/
