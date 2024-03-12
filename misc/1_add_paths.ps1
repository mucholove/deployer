# $Env:Path -split ';'

$newPath = 'C:\AppStonewood\Tools'

# Get the current system PATH
$currentPath = [System.Environment]::GetEnvironmentVariable('Path', [System.EnvironmentVariableTarget]::Machine)

# Check if the path is already in the system PATH
if (-not $currentPath.Contains($newPath))
{
    # Create the directory if it doesn't exist
    if (-not (Test-Path $newPath))
    {
        New-Item -ItemType Directory -Force -Path $newPath
    }

    # Add the new path to the system PATH
    $newSystemPath = $currentPath + ';' + $newPath
    [System.Environment]::SetEnvironmentVariable('Path', $newSystemPath, [System.EnvironmentVariableTarget]::Machine)
    
    # Add the new path to the current process PATH
    $Env:Path = $Env:Path + ';' + $newPath
}