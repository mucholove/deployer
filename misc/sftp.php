

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
