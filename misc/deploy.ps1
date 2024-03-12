# Create a new folder with the current timestamp as its name
$timestamp = Get-Date -Format "yyyy-MM-dd-HH.mm.ss"
$newDir = $timestamp # Specify your base directory path
New-Item -ItemType Directory -Path $newDir

# Change to the newly created directory
Set-Location -Path $newDir

# Open the fossil repository and update
fossil open ..\stonewood-app.fossil
fossil update

# Unset the Composer repository and install dependencies
composer config repositories.local-libs --unset
composer install
