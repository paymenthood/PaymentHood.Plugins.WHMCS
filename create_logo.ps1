Add-Type -AssemblyName System.Drawing
$width = 500
$height = 200
$bmp = New-Object System.Drawing.Bitmap($width, $height)
$graphics = [System.Drawing.Graphics]::FromImage($bmp)
$graphics.SmoothingMode = 'AntiAlias'
$graphics.TextRenderingHint = 'AntiAlias'
$bgBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(0, 123, 255))
$graphics.FillRectangle($bgBrush, 0, 0, $width, $height)
$font = New-Object System.Drawing.Font('Arial', 42, [System.Drawing.FontStyle]::Bold)
$textBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)
$text = 'PaymentHood'
$textSize = $graphics.MeasureString($text, $font)
$x = ($width - $textSize.Width) / 2
$y = ($height - $textSize.Height) / 2
$graphics.DrawString($text, $font, $textBrush, $x, $y)
$outputPath = 'c:\Repositories\startup\PaymentHood.Plugins.WHMCS\whmcs\modules\gateways\paymenthood\logo.png'
$bmp.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)
$graphics.Dispose()
$font.Dispose()
$textBrush.Dispose()
$bgBrush.Dispose()
$bmp.Dispose()
Write-Output 'PNG logo created successfully at:'
Write-Output $outputPath
