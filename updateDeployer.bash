#!/bin/bash

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


# Check if GitHub CLI is installed
if ! command -v gh &> /dev/null; then
    echo "GitHub CLI ('gh') is not installed."
    echo "Please install it before running this script."
    echo "Visit https://github.com/cli/cli#installation for installation instructions."
    exit 1
fi


# Function to increment version
increment_version() {
    local IFS=.
    local -a parts=($1)
    local increment=${2:-patch}

    case "$increment" in
        major)
            ((parts[0]++))
            parts[1]=0
            parts[2]=0
            ;;
        minor)
            ((parts[1]++))
            parts[2]=0
            ;;
        patch)
            ((parts[2]++))
            ;;
        *)
            echo "Unknown version part: $increment" >&2
            exit 1
            ;;
    esac

    echo "${parts[*]}"
}

echo "Checkig GitHub for latest version..."PS C:\Users\Gustavo Tavares\OneDrive - Full Range of Motion Lifestyle FROML EIRL\PHP2\deployer> .\updateDeployer.bash

# Get latest tag from GitHub
latest_tag=$(gh release list --repo origin --limit 1 | cut -f1)

if [[ -z "$latest_tag" ]]; then
    echo "No tags found. Assuming start version as 0.0.0."
    latest_tag="0.0.0"
fi

echo "Latest version: $latest_tag"

# Default to patch increment
increment="patch"

if [[ $1 == "-i" || $1 == "--interactive" ]]; then
    # In interactive mode, ask for version part to increment, default to patch
    read -p "Enter version part to increment (major, minor, patch) [patch]: " input_increment
    if [[ -n "$input_increment" ]]; then
        increment=$input_increment
    fi
fi

new_version=$(increment_version "$latest_tag" "$increment")

if [[ $1 == "-i" || $1 == "--interactive" ]]; then
    # Confirm the action in interactive mode
    read -p "New version will be $new_version. Proceed? (y/n) " confirm
    if [[ $confirm != "y" ]]; then
        echo "Aborting."
        exit 1
    fi
fi

# Add all changes to git
git add --all

# Commit changes with a message indicating the new version
git commit -m "Release $new_version"

# Push changes to the current branch
git push

# Create new tag for the release and push it
git tag "$new_version"
git push origin "$new_version"

echo "Version $new_version released."
