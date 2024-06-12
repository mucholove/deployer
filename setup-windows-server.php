<?php

require_once('find_autoload_file.php');

$debug = true;

$autoloadPath = getenv('COMPOSER_AUTOLOAD_PATH') ?: findAutoloadFile();
require $autoloadPath;

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

if (!$ssh->login($SERVER_CONFIG['username'], $SERVER_CONFIG['password']) || !$sftp->login($SERVER_CONFIG['username'], $SERVER_CONFIG['password'])) {
    die('Login failed');
}

// Step 1: Download SQL Server Drivers
$downloadScript = <<<EOT
\$url = "https://go.microsoft.com/fwlink/?linkid=2258816";
\$outputFile = "C:\\xampp\\php\\ext\\SQLSRV60.zip";
\$client = new-object System.Net.WebClient;
\$client.DownloadFile(\$url, \$outputFile);
EOT;

// Step 2: Extract the drivers
$extractScript = <<<EOT
Add-Type -AssemblyName System.IO.Compression.FileSystem;
\$zipPath = "C:\\xampp\\php\\ext\\SQLSRV60.zip";
\$extractPath = "C:\\xampp\\php\\ext\\sqlsrv";
[System.IO.Compression.ZipFile]::ExtractToDirectory(\$zipPath, \$extractPath);
EOT;

// Step 3: Determine PHP version and copy appropriate DLLs
$copyDllScript = <<<EOT
\$phpVersion = (php -i | Select-String 'PHP Version' | ForEach-Object { \$_ -replace '^.*PHP Version => ', '' }).Trim();
\$phpMajorVersion = \$phpVersion.Split('.')[0];
\$phpMinorVersion = \$phpVersion.Split('.')[1];
\$dllPath = "C:\\xampp\\php\\ext\\sqlsrv";

\$dllFiles = switch ("\$phpMajorVersion.\$phpMinorVersion") {
    "8.1" { "php_pdo_sqlsrv_81_ts_x64.dll", "php_sqlsrv_81_ts_x64.dll" }
    "8.2" { "php_pdo_sqlsrv_82_ts_x64.dll", "php_sqlsrv_82_ts_x64.dll" }
    "8.3" { "php_pdo_sqlsrv_83_ts_x64.dll", "php_sqlsrv_83_ts_x64.dll" }
    default { throw "Unsupported PHP version: \$phpVersion" }
}

foreach (\$dllFile in \$dllFiles) {
    Copy-Item -Path "\$dllPath\\\$dllFile" -Destination "C:\\xampp\\php\\ext\\\$dllFile" -Force;
}
EOT;

// Step 4: Modify php.ini
$modifyIniScript = <<<EOT
\$phpIniPath = "C:\\xampp\\php\\php.ini";
\$phpIniContent = Get-Content \$phpIniPath;

\$requiredExtensions = @(
    "extension=bz2",
    "extension=curl",
    "extension=fileinfo",
    "extension=gettext",
    "extension=imap",
    "extension=mbstring",
    "extension=exif",
    "extension=mysqli",
    "extension=pdo_mysql",
    "extension=pdo_pgsql",
    "extension=pdo_sqlite",
    "extension=pgsql",
    "extension=zip",
    "zend_extension=opcache",
    "extension=php_sqlsrv.dll",
    "extension=php_pdo_sqlsrv.dll"
);

foreach (\$extension in \$requiredExtensions) {
    if (\$phpIniContent -match "^\s*;\s*\$extension") {
        # Uncomment the extension if it is commented out
        (Get-Content \$phpIniPath) -replace "^\s*;\s*(\$extension)", "\$extension" | Set-Content \$phpIniPath
    } elseif (\$phpIniContent -notcontains \$extension) {
        # Add the extension if it is not present
        Add-Content -Path \$phpIniPath -Value \$extension;
    }
}
EOT;

// Execute scripts on the remote server
$ssh->exec($downloadScript);
$ssh->exec($extractScript);
$ssh->exec($copyDllScript);
$ssh->exec($modifyIniScript);

// Restart Apache
$ssh->exec('C:\\xampp\\apache\\bin\\httpd.exe -k restart');

echo "SQL Server drivers installed and XAMPP configured successfully.";
?>
