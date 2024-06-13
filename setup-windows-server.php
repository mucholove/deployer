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
$ssh  = new SSH2($SERVER_CONFIG['host'], $SERVER_CONFIG['port']);
$sftp = new SFTP($SERVER_CONFIG['host'], $SERVER_CONFIG['port']);

echo "Connecting to SSH and SFTP...\n";
if (!$ssh->login($SERVER_CONFIG['username'], $SERVER_CONFIG['password']))
{
    die('SSH login failed');
}

if (!$sftp->login($SERVER_CONFIG['username'], $SERVER_CONFIG['password'])) 
{
    die('SFTP login failed');
}

echo "Connected successfully.\n";

// Enable-PSRemoting -Force

// Check if PowerShell remoting is enabled
// $checkCommand = "powershell.exe -Command \"Test-WSMan\"";
// $output = $ssh->exec($checkCommand);

if (strpos($output, 'wsmid') === false) {
    // PowerShell remoting is not enabled, so enable it
    echo "PowerShell remoting is not enabled. Enabling it now...\n";
    $enableCommand = "powershell.exe -Command \"Enable-PSRemoting -Force\"";
    $ssh->exec($enableCommand);
    echo "PowerShell remoting has been enabled.\n";
} else {
    echo "PowerShell remoting is already enabled.\n";
}

// Set the PowerShell command to execute
$psCommand = 'Get-Process';

// Execute the PowerShell command remotely
$output = $ssh->exec("powershell.exe -Command \"$psCommand\"");

// Display the output
echo "Output:\n" . $output;


// PowerShell script to check if FTP is enabled and to enable it if necessary
$checkAndEnableFTPScript = <<<EOT
\$ftpFeature = Get-WindowsFeature Web-Ftp-Server
if (\$ftpFeature.Installed -eq \$false) {
    echo "FTP is not enabled. Enabling FTP...\n"
    Install-WindowsFeature Web-Ftp-Server
    Start-Service W3SVC
    echo "FTP has been enabled and started.\n"
} else {
    echo "FTP is already enabled.\n"
}
EOT;

// Execute the PowerShell script
echo "Checking and enabling FTP...\n";
$output = $ssh->exec("powershell -command \"$checkAndEnableFTPScript\"");
echo $output;

// die();

$methodForPHPVersion = "PHP_VERSION";
$phpVersion          = null;
$phpVersionOutput    = null;

switch ($methodForPHPVersion)
{
    case "PHP_VERSION":
        $phpVersionOutput = $ssh->exec('php -r "echo PHP_VERSION;"');
        break;
    case "PHPINFO":
        $phpInfoOutput = $ssh->exec('php -r "phpinfo();"');
        preg_match('/PHP Version => ([0-9]+\.[0-9]+\.[0-9]+)/', $phpInfoOutput, $matches);
        if (count($matches) > 1) {
            $phpVersion = $matches[1];
        }
        break;
    case "SCRIPT_WITH_phpversion":
    default:
        // Create a temporary PHP script on the remote server to get the PHP version
        $phpVersionScript = '<?php echo phpversion(); ?>';
        $tempPhpVersionScriptPath = 'C:\\xampp\\php\\temp_php_version.php';
        $sftp->put($tempPhpVersionScriptPath, $phpVersionScript);
        // Execute the temporary PHP script
        $phpVersionOutput = $ssh->exec("php $tempPhpVersionScriptPath");
        
}

$phpVersion = trim($phpVersionOutput);

echo "PHP version on remote server: $phpVersion\n";

$phpMajorMinorVersion = implode('.', array_slice(explode('.', $phpVersion), 0, 2));



$dllFiles = [];

switch ($phpMajorMinorVersion) {
    case "8.1":
        $dllFiles = [
            "php_pdo_sqlsrv_81_ts_x64.dll", 
            "php_sqlsrv_81_ts_x64.dll",
        ];
        break;
    case "8.2":
        $dllFiles = [
            "php_pdo_sqlsrv_82_ts_x64.dll", 
            "php_sqlsrv_82_ts_x64.dll",
        ];
        break;
    case "8.3":
        $dllFiles = [
            "php_pdo_sqlsrv_83_ts_x64.dll", 
            "php_sqlsrv_83_ts_x64.dll",
        ];
        break;
    default:
        die("Unsupported PHP version: $phpVersion\n");
}

$needToUploadDLLFiles = true;

function doesFileExistsOnSSHConnection($ssh, $remoteFilePath)
{
    $command = "test -e '$remoteFilePath' && echo 'File exists' || echo 'File does not exist'";
    $fileExists = $ssh->exec($command);

    if ($fileExists == "File exists") 
    {
        return true;
    }
    else
    {
        return false;
    }
}


// Upload the relevant DLL files using SFTP
foreach ($dllFiles as $dllFile) 
{
    $remoteDLLPath = "C:\\xampp\\php\\ext\\".$dllFile;

    $fileExists = doesFileExistsOnSSHConnection($ssh, $remoteDLLPath);

    if ($fileExists) 
    {
        $needToUploadDLLFiles = false;
        echo "SqlSrv DLL file: $remoteDLLPath already exist.\n";
    } 
    else 
    {
        echo "SqlSrv DLL file: $remoteDLLPath not found.\n";
    }
}



if ($needToUploadDLLFiles)
{
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
    if ($res === TRUE) 
    {
        $extractPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "sqlsrv";
        $zip->extractTo($extractPath);
        $zip->close();
        echo "Extracted SQL Server drivers to $extractPath\n";
    } 
    else 
    {
        die("Error: Failed to extract SQLSRV60.zip\n");
    }

    echo "DLL files to be copied: " . implode(', ', $dllFiles) . "\n";

    // Upload the relevant DLL files using SFTP
    foreach ($dllFiles as $dllFile) 
    {
        $localDllPath = $extractPath . DIRECTORY_SEPARATOR . $dllFile;

        if (!file_exists($localDllPath)) 
        {
            die("Error: $localDllPath not found\n");
        }

        $remoteDllPath = "C:\\xampp\\php\\ext\\" . $dllFile;
        echo "Uploading $dllFile to $remoteDllPath...\n";

        $dllData = file_get_contents($localDllPath);

        if (!$dllData) 
        {
            die("Error: Failed to read $localDllPath\n");
        }

        $didUpload = $sftp->put($remoteDllPath, $dllData);

        if ($didUpload) 
        {
            echo "Uploaded $dllFile successfully.\n";
        } 
        else 
        {

            $ftpConn = ftp_connect($SERVER_CONFIG['host']) or die("Could not connect to $ftpServer");
            $login   = ftp_login($ftpConn, 
                $SERVER_CONFIG['username'], 
                $SERVER_CONFIG['username']);

            if (!function_exists('ftp_file_exists'))
            {
                function ftp_file_exists($ftpConn, $file) {
                    $fileList = ftp_nlist($ftpConn, dirname($file));
                    return in_array($file, $fileList);
                }
            }

            if (ftp_put($ftpConn, $remoteDllPath, $localDllPath, FTP_BINARY)) 
            {
                echo "Uploaded php.ini successfully.\n";
            } 



            die("Error: Failed to upload $dllFile\n");
        }
    }
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
