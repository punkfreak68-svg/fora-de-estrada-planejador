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

    # Background: rounded square, dark olive-green (matches the app's compass logo)
    $bgColor = [System.Drawing.Color]::FromArgb(255, 58, 74, 30)
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
    $cy = $Size / 2.0
    $s = [double]$Size * $ContentScale

    # Stylized compass: two concentric rings with N/E/S/W tick marks, and a
    # kite-shaped needle (cream north point, olive-green south point) with a
    # small center dot.
    $ringColor = [System.Drawing.Color]::FromArgb(255, 163, 185, 117)
    $ringPen = New-Object System.Drawing.Pen($ringColor, [Math]::Max(1.0, $s * 0.012))
    $innerRingPen = New-Object System.Drawing.Pen($ringColor, [Math]::Max(1.0, $s * 0.007))

    $outerR = $s * 0.31
    $innerR = $s * 0.245
    $g.DrawEllipse($ringPen, $cx - $outerR, $cy - $outerR, $outerR * 2, $outerR * 2)
    $g.DrawEllipse($innerRingPen, $cx - $innerR, $cy - $innerR, $innerR * 2, $innerR * 2)

    # Tick marks at N/E/S/W, straddling the outer ring
    $tickHalf = $s * 0.05
    $tickPen = New-Object System.Drawing.Pen($ringColor, [Math]::Max(1.0, $s * 0.014))
    $g.DrawLine($tickPen, $cx, $cy - $outerR - $tickHalf, $cx, $cy - $outerR + $tickHalf)
    $g.DrawLine($tickPen, $cx, $cy + $outerR - $tickHalf, $cx, $cy + $outerR + $tickHalf)
    $g.DrawLine($tickPen, $cx - $outerR - $tickHalf, $cy, $cx - $outerR + $tickHalf, $cy)
    $g.DrawLine($tickPen, $cx + $outerR - $tickHalf, $cy, $cx + $outerR + $tickHalf, $cy)

    # Needle: kite shape split at the horizontal midline
    $needleHalfWidth = $s * 0.09
    $topLen = $s * 0.24
    $bottomLen = $s * 0.20

    $topColor = [System.Drawing.Color]::FromArgb(255, 233, 227, 208)
    $bottomColor = [System.Drawing.Color]::FromArgb(255, 133, 160, 90)
    $topBrush = New-Object System.Drawing.SolidBrush $topColor
    $bottomBrush = New-Object System.Drawing.SolidBrush $bottomColor

    $topTipY = $cy - $topLen
    $bottomTipY = $cy + $bottomLen
    $needleLeftX = $cx - $needleHalfWidth
    $needleRightX = $cx + $needleHalfWidth

    $topTri = @(
        New-Object System.Drawing.PointF($cx, $topTipY)
        New-Object System.Drawing.PointF($needleRightX, $cy)
        New-Object System.Drawing.PointF($needleLeftX, $cy)
    )
    $bottomTri = @(
        New-Object System.Drawing.PointF($cx, $bottomTipY)
        New-Object System.Drawing.PointF($needleRightX, $cy)
        New-Object System.Drawing.PointF($needleLeftX, $cy)
    )
    $g.FillPolygon($topBrush, $topTri)
    $g.FillPolygon($bottomBrush, $bottomTri)

    # Center dot
    $dotR = $s * 0.045
    $g.FillEllipse($topBrush, $cx - $dotR, $cy - $dotR, $dotR * 2, $dotR * 2)

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
