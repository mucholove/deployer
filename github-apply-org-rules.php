<?php

require_once('find_autoload_file.php');

$debug        = true;

$autoloadPath = getenv('COMPOSER_AUTOLOAD_PATH') ?: findAutoloadFile();

// echo "Autoload Path: ".$autoloadPath."\n";

require $autoloadPath;

$serverOS = $SERVER_CONFIG["SERVER_OS"] ?? "windows";

$GTK_DIRECTORY_SEPERATOR = "/";

if ($serverOS == "windows")
{
    $GTK_DIRECTORY_SEPERATOR = "\\";
}


if ($argc < 2) 
{
    die("Usage: php script.php <config_file_path>\n");
}

$configFilePath = null;

if (isRootPath($argv[1]))
{
    $configFilePath = $argv[1];
}
else
{
    $fileName = $argv[1]; // Get the server name from the command-line argument
    $rootLevel  = findRootLevel();
    $configFilePath = implode($GTK_DIRECTORY_SEPERATOR, [
        $rootLevel,
        ".secret",
        "github_orgs",
        $fileName.".json",
    ]);
    
}

$config = json_decode(file_get_contents($configFilePath), true);

if (json_last_error() !== JSON_ERROR_NONE) 
{
    die("Error reading configuration file: " . json_last_error_msg() . "\n");
}


// Get configuration values
$organization     = $config['organization'];
$username         = $config['username'];
$usersWithApprove = $config['users_with_approve'];
$GH_TOKEN         = $config['GH_TOKEN'];

use Github\Client;
use Github\AuthMethod;

// Create a GitHub client
$client = new Client();
$client->authenticate($GH_TOKEN, null, AuthMethod::ACCESS_TOKEN);

// Fetch repositories of the organization
$repositories = $client->api('organization')->repositories($organization);

foreach ($repositories as $repo) {
    $repoName = $repo['name'];

    // Apply branch protection rules
    $protectionParams = [
        'required_status_checks' => [
            'strict' => true,
            'contexts' => []
        ],
        'enforce_admins' => false,
        'required_pull_request_reviews' => [
            'dismiss_stale_reviews' => true,
            'require_code_owner_reviews' => true,
            'required_approving_review_count' => 1
        ],
        'restrictions' => [
            'users' => $usersWithApprove,
            'teams' => []
        ]
    ];

    try {
        $client->api('repo')->protection()->update(
            $organization,
            $repoName,
            'main', // Branch name
            $protectionParams
        );
        echo "Branch protection applied to $repoName\n";
    } catch (Exception $e) {
        echo "Error applying branch protection to $repoName: " . $e->getMessage() . "\n";
    }
}
