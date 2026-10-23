# Turns the PNGs taken by LandingScreenshotsTest into the 1280 px wide JPEGs the landing page serves (public/assets/landing).
# usage: powershell -File tests/Browser/landing-shots.ps1
Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$source = Join-Path $root 'tests/Browser/screenshots'
$target = Join-Path $root 'public/assets/landing'
$map = [ordered]@{ dashboard = 'dashboard'; sales = 'sales'; products = 'products'; sellreport = 'sellreport' }
$codec = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() | Where-Object { $_.MimeType -eq 'image/jpeg' }
$params = New-Object System.Drawing.Imaging.EncoderParameters(1)
$params.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter([System.Drawing.Imaging.Encoder]::Quality, [long]80)

foreach ($name in $map.Keys) {
    $png = [System.Drawing.Image]::FromFile((Join-Path $source "landing-$name.png"))
    $cropWidth = $png.Width - 15 # the page scrollbar
    $height = [int][math]::Round(1280 * $png.Height / $cropWidth)
    $bitmap = New-Object System.Drawing.Bitmap(1280, $height)
    $graphics = [System.Drawing.Graphics]::FromImage($bitmap)
    $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $graphics.DrawImage($png, (New-Object System.Drawing.Rectangle(0, 0, 1280, $height)), 0, 0, $cropWidth, $png.Height, [System.Drawing.GraphicsUnit]::Pixel)
    $bitmap.Save((Join-Path $target "$($map[$name]).jpg"), $codec, $params)
    $graphics.Dispose(); $bitmap.Dispose(); $png.Dispose()
    Write-Output "$name -> 1280x$height"
}
