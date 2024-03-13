<?php

require __DIR__ . '/vendor/autoload.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
// Check if a first argument is passed
if ($argc > 1) 
{
    // Use the first argument as the key pair name
    $keyPairName = $argv[1];
} 
else 
{
    // Ask for the key pair name
    echo "Enter the name for this key pair: ";
    $handle = fopen ("php://stdin","r");
    $keyPairName = trim(fgets($handle));
    fclose($handle); // Close the handle after use
    if (empty($keyPairName)) {
        echo "Key pair name cannot be empty.\n";
        exit;
    }
}

// Specify the folder and key file names
$folderPath = getenv("HOME").'/.ssh/';
$privateKeyFile = $folderPath . $keyPairName;
$publicKeyFile = $privateKeyFile . '.pub';

// Create the folder if it doesn't exist
if (!file_exists($folderPath))
{
    mkdir($folderPath, 0700, true);
}

// Generate a new RSA key pair
$key = RSA::createKey();

// Export the private key
$privateKey = $key->toString('PKCS1');
file_put_contents($privateKeyFile, $privateKey);

$multiLineFormat = 'PKCS1';

$singleLineFormat = 'PKCS8';
$singleLineFormat = 'OpenSSH';

// Export the public key
$publicKey = $key->getPublicKey()->toString('OpenSSH');
file_put_contents($publicKeyFile, $publicKey);

echo "SSH key pair generated and saved.\n";

// Provide instructions for adding the public key to the server's authorized_keys
echo "Run the following command to add the public key to your server's authorized_keys:\n";
echo "ssh-copy-id -i {$publicKeyFile} your_username@your_server_ip\n";

?>
