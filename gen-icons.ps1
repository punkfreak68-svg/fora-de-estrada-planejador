Add-Type -AssemblyName System.Drawing

function New-AppIcon {
    param(
        [int]$Size,
        [string]$OutPath,
        [double]$ContentScale = 1.0
    )

    $bmp = New-Object System.Drawing.Bitmap $Size, $Size
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.Clear([System.Drawing.Color]::Transparent)

    # Background: rounded square, dark green (app theme background)
    $bgColor = [System.Drawing.Color]::FromArgb(255, 27, 31, 22)
    $bgBrush = New-Object System.Drawing.SolidBrush $bgColor
    $radius = [double]$Size * 0.18
    $d = $radius * 2
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $path.AddArc(0, 0, $d, $d, 180, 90)
    $path.AddArc($Size - $d, 0, $d, $d, 270, 90)
    $path.AddArc($Size - $d, $Size - $d, $d, $d, 0, 90)
    $path.AddArc(0, $Size - $d, $d, $d, 90, 90)
    $path.CloseFigure()
    $g.FillPath($bgBrush, $path)

    $cx = $Size / 2.0
    $scale = $ContentScale

    # Orange pin/teardrop shape (matches the in-app numbered route markers)
    $pinColor = [System.Drawing.Color]::FromArgb(255, 224, 138, 44)
    $pinBrush = New-Object System.Drawing.SolidBrush $pinColor
    $circleR = $Size * 0.27 * $scale
    $circleCy = $Size * (0.5 - 0.12 * $scale)

    $pinPath = New-Object System.Drawing.Drawing2D.GraphicsPath
    $pinPath.FillMode = [System.Drawing.Drawing2D.FillMode]::Winding
    $pinPath.AddEllipse($cx - $circleR, $circleCy - $circleR, $circleR * 2, $circleR * 2)

    $tipY = $circleCy + $circleR * 1.9
    $leftX = $cx - $circleR * 0.85
    $rightX = $cx + $circleR * 0.85
    $topY = $circleCy + $circleR * 0.55
    $tri = @(
        New-Object System.Drawing.PointF($leftX, $topY)
        New-Object System.Drawing.PointF($rightX, $topY)
        New-Object System.Drawing.PointF($cx, $tipY)
    )
    $pinPath.AddPolygon($tri)
    $g.FillPath($pinBrush, $pinPath)

    # White ring around the circle part (contrast, matches in-app marker style)
    $ringWidth = [Math]::Max(1.0, $Size * 0.018)
    $whitePen = New-Object System.Drawing.Pen([System.Drawing.Color]::White, $ringWidth)
    $g.DrawEllipse($whitePen, $cx - $circleR, $circleCy - $circleR, $circleR * 2, $circleR * 2)

    # Simple white mountain silhouette inside the circle
    $whiteBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)
    $mR = $circleR * 0.62
    $mPoints = @(
        New-Object System.Drawing.PointF(($cx - $mR), ($circleCy + $mR * 0.55))
        New-Object System.Drawing.PointF(($cx - $mR * 0.15), ($circleCy - $mR * 0.6))
        New-Object System.Drawing.PointF(($cx + $mR * 0.25), ($circleCy - $mR * 0.05))
        New-Object System.Drawing.PointF(($cx + $mR * 0.6), ($circleCy - $mR * 0.5))
        New-Object System.Drawing.PointF(($cx + $mR), ($circleCy + $mR * 0.55))
    )
    $g.FillPolygon($whiteBrush, $mPoints)

    $bmp.Save($OutPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bmp.Dispose()
}

$dir = "C:\Claude\jipe-rotas\icons"
New-AppIcon -Size 512 -OutPath "$dir\icon-512.png" -ContentScale 1.0
New-AppIcon -Size 192 -OutPath "$dir\icon-192.png" -ContentScale 1.0
New-AppIcon -Size 180 -OutPath "$dir\apple-touch-icon.png" -ContentScale 1.0
New-AppIcon -Size 32  -OutPath "$dir\favicon-32.png" -ContentScale 1.0
New-AppIcon -Size 512 -OutPath "$dir\icon-maskable-512.png" -ContentScale 0.62
New-AppIcon -Size 192 -OutPath "$dir\icon-maskable-192.png" -ContentScale 0.62

Write-Host "Icons generated."
