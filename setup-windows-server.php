<?php

require_once('find_autoload_file.php');

$debug = true;

$autoloadPath = getenv('COMPOSER_AUTOLOAD_PATH') ?: findAutoloadFile();
require $autoloadPath;

use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;

$serverOS = $SERVER_CONFIG["SERVER_OS"] ?? "windows";
$GTK_DIRECTORY_SEPARATOR = ($serverOS == "windows") ? "\\" : "/";

if ($argc < 2) {
    die("Usage: php script.php <server_name>\n");
}

$serverName = $argv[1]; // Get the server name from the command-line argument
$rootLevel = findRootLevel();
$credentialsFilePath = implode($GTK_DIRECTORY_SEPARATOR, [
    $rootLevel,
    ".secret",
    "servers",
    $serverName.".php",
]);

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

// Initialize SSH and SFTP connections
$ssh = new SSH2($SERVER_CONFIG['host'], $SERVER_CONFIG['port']);
$sftp = new SFTP($SERVER_CONFIG['host'], $SERVER_CONFIG['port']);

echo "Connecting to SSH and SFTP...\n";
if (!$ssh->login($SERVER_CONFIG['username'], $SERVER_CONFIG['password']) || !$sftp->login($SERVER_CONFIG['username'], $SERVER_CONFIG['password'])) {
    die('Login failed');
}
echo "Connected successfully.\n";

// Step 1: Download SQL Server Drivers
echo "Downloading SQL Server drivers...\n";
$sqlsrvUrl = "https://go.microsoft.com/fwlink/?linkid=2258816";
$sqlsrvZipLocalPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "SQLSRV60.zip";
file_put_contents($sqlsrvZipLocalPath, file_get_contents($sqlsrvUrl));
echo "Downloaded SQL Server drivers to $sqlsrvZipLocalPath\n";

// Step 2: Extract the drivers locally
echo "Extracting SQL Server drivers...\n";
$zip = new ZipArchive;
$res = $zip->open($sqlsrvZipLocalPath);
if ($res === TRUE) {
    $extractPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "sqlsrv";
    $zip->extractTo($extractPath);
    $zip->close();
    echo "Extracted SQL Server drivers to $extractPath\n";
} else {
    die("Error: Failed to extract SQLSRV60.zip\n");
}

// Step 3: Determine PHP version and copy appropriate DLLs
echo "Determining PHP version on remote server...\n";
$phpVersionOutput = $ssh->exec("php -r 'echo PHP_VERSION;'");
$phpVersion = trim($phpVersionOutput);
echo "PHP version on remote server: $phpVersion\n";

$phpMajorMinorVersion = implode('.', array_slice(explode('.', $phpVersion), 0, 2));

$dllFiles = [];
switch ($phpMajorMinorVersion) {
    case "8.1":
        $dllFiles = ["php_pdo_sqlsrv_81_ts_x64.dll", "php_sqlsrv_81_ts_x64.dll"];
        break;
    case "8.2":
        $dllFiles = ["php_pdo_sqlsrv_82_ts_x64.dll", "php_sqlsrv_82_ts_x64.dll"];
        break;
    case "8.3":
        $dllFiles = ["php_pdo_sqlsrv_83_ts_x64.dll", "php_sqlsrv_83_ts_x64.dll"];
        break;
    default:
        die("Unsupported PHP version: $phpVersion\n");
}
echo "DLL files to be copied: " . implode(', ', $dllFiles) . "\n";

// Upload the relevant DLL files using SFTP
foreach ($dllFiles as $dllFile) {
    $localDllPath = $extractPath . DIRECTORY_SEPARATOR . $dllFile;
    $remoteDllPath = "C:\\xampp\\php\\ext\\" . $dllFile;
    echo "Uploading $dllFile to $remoteDllPath...\n";
    $sftp->put($remoteDllPath, file_get_contents($localDllPath));
    echo "Uploaded $dllFile successfully.\n";
}

// Upload the modified php.ini file using SFTP
$localPhpIniPath = __DIR__ . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "windows" . DIRECTORY_SEPARATOR ."php.ini";
$remotePhpIniPath = "C:\\xampp\\php\\php.ini";
echo "Uploading php.ini to $remotePhpIniPath...\n";
$sftp->put($remotePhpIniPath, file_get_contents($localPhpIniPath));
echo "Uploaded php.ini successfully.\n";

// Restart Apache
echo "Restarting Apache...\n";
$ssh->exec('C:\\xampp\\apache\\bin\\httpd.exe -k restart');
echo "Restarted Apache successfully.\n";

echo "SQL Server drivers installed and XAMPP configured successfully.\n";
?>
