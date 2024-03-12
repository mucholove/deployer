# Check if GitHub CLI is installed
try {
    $null = Get-Command gh -ErrorAction Stop
} catch {
    Write-Host "GitHub CLI ('gh') is not installed."
    Write-Host "Please install it before running this script."
    Write-Host "Visit https://github.com/cli/cli#installation for installation instructions."
    exit 1
}

# ------ Mac -------
# =====================
# brew install gh

# ------ Ubuntu -------
# =====================
# curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg | sudo dd of=/usr/share/keyrings/githubcli-archive-keyring.gpg
# echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | sudo tee /etc/apt/sources.list.d/github-cli.list > /dev/null
# sudo apt update
# sudo apt install gh

# ------ Windows -------
# =====================
# choco install gh
# scoop install gh

# Check GitHub for latest version
Write-Host "Checking GitHub for latest version..."

# Get latest tag from GitHub
$latestTag = gh release list --repo origin --limit 1 | Select-String -Pattern "\S+" -AllMatches | ForEach-Object { $_.Matches } | ForEach-Object { $_.Value } | Select-Object -First 1

if (-z $latestTag) {
    Write-Host "No tags found. Assuming start version as 0.0.0."
    $latestTag = "0.0.0"
}

Write-Host "Latest version: $latestTag"

# Default to patch increment
$increment = "patch"

# Interactive mode check
$interactive = $false
if ($args.Count -gt 0 -and $args[0] -eq "-i" -or $args[0] -eq "--interactive") {
    $interactive = $true
    # In interactive mode, ask for version part to increment, default to patch
    $inputIncrement = Read-Host "Enter version part to increment (major, minor, patch) [patch]"
    if ($inputIncrement) {
        $increment = $inputIncrement
    }
}

function Increment-Version {
    param (
        [string]$version,
        [string]$part = "patch"
    )

    $parts = $version.Split('.')
    switch ($part) {
        "major" {
            $parts[0] = [int]$parts[0] + 1
            $parts[1] = 0
            $parts[2] = 0
        }
        "minor" {
            $parts[1] = [int]$parts[1] + 1
            $parts[2] = 0
        }
        "patch" {
            $parts[2] = [int]$parts[2] + 1
        }
        default {
            Write-Error "Unknown version part: $part"
            exit 1
        }
    }

    return $parts -join '.'
}

$newVersion = Increment-Version -version $latestTag -part $increment

if ($interactive) {
    # Confirm the action in interactive mode
    $confirm = Read-Host "New version will be $newVersion. Proceed? (y/n)"
    if ($confirm -ne "y") {
        Write-Host "Aborting."
        exit 1
    }
}

# Git operations (make sure to run in a Git-enabled shell or use Git commands explicitly)
git add --all
git commit -m "Release $newVersion"
git push
git tag $newVersion
git push origin $newVersion

Write-Host "Version $newVersion released."
