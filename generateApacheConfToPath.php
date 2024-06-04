<?php
$rootLevel    = dirname(__FILE__, 4);
$vendorLevel  = dirname(__FILE__, 3);
$autoloadFile = $rootLevel."/vendor/autoload.php";

echo "Will autoload from...: ".$autoloadFile."\n";

require $autoloadFile;

$serverAliases = [];

if ($argc < 6) 
{
    $message = "Usage: php script.php ";
    $message .= " <documentRoot>";
    $message .= " <writeToPath>";
    $message .= " <serverName>";
    $message .= " <certificateKeyFile>";
    $message .= " <certificateFile>";
    $message .= "\n";
}

$documentRoot       = $argv[1];
$writeToPath        = $argv[2];
$serverName         = $argv[3];
$certificateFile    = $argv[4];
$certificateKeyFile = $argv[5];


function getConfString(
    $serverName, 
    $documentRoot, 
    $certificateFile, 
    $certificateKeyFile, 
    $serverAliases
){
    if ($certificateFile)
    {
        $certificateFile = $certificateFile == 'null' ? null : $certificateFile;
    }

    if ($certificateKeyFile)
    {
        $certificateKeyFile = $certificateKeyFile == 'null' ? null : $certificateKeyFile;
    }

    ob_start();
    ?>
    # CERTIFICATES AND VARIABLES
    <VirtualHost *:80>
        ServerName <?php echo $serverName."\n\n"; ?>
        <?php
        foreach ($serverAliases as $serverAlias) {
            echo "ServerAlias $serverAlias\n";
        }
        ?>
        DocumentRoot <?php echo $documentRoot; ?>

        # Redirect all HTTP traffic to HTTPS
        RewriteEngine On
        RewriteCond %{HTTPS} off
        RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
    </VirtualHost>

    <?php if (empty($certificateFile) || empty($certificateKeyFile)) {
        return ob_get_clean();
    } ?>

    <VirtualHost *:443>
        ServerName <?php echo $serverName."\n\n"; ?>
        <?php
        foreach ($serverAliases as $serverAlias) {
            echo "ServerAlias $serverAlias\n";
        }
        ?>
        DocumentRoot "<?php echo $documentRoot; ?>"

        <?php if ($certificateFile && $certificateKeyFile): ?>
            SSLEngine on
            SSLCertificateFile "<?php echo $certificateFile; ?>"
            SSLCertificateKeyFile "<?php echo $certificateKeyFile; ?>"
        <?php endif; ?>

        <Directory "<?php echo $documentRoot; ?>">
            DirectoryIndex index.php
            AllowOverride All
            Require all granted
            <FilesMatch \.php$>
                SetHandler application/x-httpd-php
            </FilesMatch>
        </Directory>
    </VirtualHost>
    
    <?php 
    return ob_get_clean(); // Get the buffer content and clean the buffer
}


$apacheConf = getConfString(
    $serverName, 
    $documentRoot,
    $certificateFile, 
    $certificateKeyFile, 
    $serverAliases
);

$filePutContentsResults = file_put_contents($writeToPath, $apacheConf);


// Attempt to write the Apache configuration to the specified path and handle failure
if ($filePutContentsResults === false) 
{
    die("Fatal: Failed to write the Apache configuration to $writeToPath\n");
}

echo "Apache configuration has been successfully written to $writeToPath\n";
