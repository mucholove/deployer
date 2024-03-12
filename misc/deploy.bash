#!/bin/bash

# Create a new folder with the current timestamp as its name
timestamp=$(date +%Y%m%d%H%M%S)
newDir=$timestamp # Specify your base directory path
mkdir -p "$newDir"

# Change to the newly created directory
cd "$newDir"

# Open the fossil repository and update
fossil open ../stonewood-app.fossil
fossil set 
fossil update

# Unset the Composer repository and install dependencies
composer config repositories.local-libs --unset
composer install

# https://docs.gitlab.com/omnibus/settings/nginx.html#using-a-non-bundled-web-server
# curl https://packages.gitlab.com/install/repositories/gitlab/gitlab-ee/script.deb.sh | sudo bash
# sudo EXTERNAL_URL="http://git.palo.do" apt-get install gitlab-ee

I want to make a script that...
connects to a Windows server on IP Address 192.168.20.221
Goes to "C:\AppStonewoodGitRepos" and creates a new folder with current datetime
Clones the repository at https://github.com/Stonewood-RD/stonewood-app into the previously created folder
Copies the file: C:\AppStonewood\Config\Composer\auth.json to the git repo
Runs... "composer install"
Completes the follwoing template ponting to the "/www" directoru
--------------------------------------------------------------------------------------
# CERTIFCATES AND VARIABLES
DEFINE APP_STD_CERTROOT "C:/AppStonewood/Certificate"
DEFINE IIS_SERVER_CERTROOT "C:/AppStonewood/Certificate"

<VirtualHost *:80>
    ServerName app.stonewood.com.do
    ServerAlias appstonewood
    ServerAlias iis-server-stwd
    DocumentRoot "$PREVIOUSLY_CREATED_FOLDER_WWW"

    # Redirect all HTTP traffic to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost _default_:443>
    ServerName app.stonewood.com.do
    ServerAlias appstonewood
    ServerAlias iis-server-stwd
    DocumentRoot DocumentRoot "$PREVIOUSLY_CREATED_FOLDER_WWW"

    SSLEngine on
    SSLCertificateFile "${APP_STD_CERTROOT}/app.stonewood.com.do-chain.pem"
    SSLCertificateKeyFile "${APP_STD_CERTROOT}/app.stonewood.com.do-key.pem"


    <Directory DocumentRoot "$PREVIOUSLY_CREATED_FOLDER_WWW">
        DirectoryIndex index.php
        AllowOverride All
        Require all granted
        <FilesMatch \.php$>
            SetHandler application/x-httpd-php
        </FilesMatch>
    </Directory>
</VirtualHost>
----------------------------------------------------------------------------------------
Also allows one to run a "ROLLABCK" function which deletes the current deploy and goes back to the latest previposuly deployed folder
