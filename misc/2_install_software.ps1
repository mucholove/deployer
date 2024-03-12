
# Define the URLs and destination paths for the software packages
$softwarePackages = @(
    @{ Url = 'https://www.fossil-scm.org/home/uv/fossil-windows-x64.zip'; Destination = Join-Path $newPath 'fossil-windows-x64.zip' },
    @{ Url = 'https://www.apachefriends.org/xampp-files/8.0.10/xampp-windows-x64-8.0.10-0-VS16-installer.exe'; Destination = Join-Path $newPath 'xampp-installer.exe' },
    @{ Url = 'https://sqlitestudio.pl/files/sqlitestudio3/complete/win/SQLiteStudio-3.3.3.zip'; Destination = Join-Path $newPath 'SQLiteStudio.zip' }
)
foreach ($package in $softwarePackages)
{
    # Download the software package
    Invoke-WebRequest -Uri $package.Url -OutFile $package.Destination
    # Unzip the package if it is a zip file
    if ($package.Destination.EndsWith('.zip'))
    {
        Expand-Archive -Path $package.Destination -DestinationPath $newPath -Force
        # Remove the zip file after extraction
        Remove-Item $package.Destination
    }
    else
    {
        # If it's an executable, run the installer (for XAMPP in this case)
        Start-Process -FilePath $package.Destination -ArgumentList "/install /silent" -Wait
    }
}
