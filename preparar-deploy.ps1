# Monta a pasta pronta para upload na hospedagem (cPanel/FTP).
# Uso:  .\preparar-deploy.ps1 -ChaveGoogle "AIza..."
# A chave da Places API e injetada so no arquivo gerado em dist\rotas
# (dist\ esta no .gitignore, entao a chave nunca vai pro GitHub).
param(
    [Parameter(Mandatory = $true)]
    [string]$ChaveGoogle
)

$ErrorActionPreference = "Stop"
$raiz = $PSScriptRoot
$saida = Join-Path $raiz "dist\rotas"

if (Test-Path $saida) { Remove-Item $saida -Recurse -Force }
New-Item -ItemType Directory -Path $saida | Out-Null

foreach ($arq in @("index.html", "sw.js", "manifest.json")) {
    Copy-Item (Join-Path $raiz $arq) $saida
}
Copy-Item (Join-Path $raiz "icons") (Join-Path $saida "icons") -Recurse

# Injeta a chave (mesmo papel do sed que rodava no build do Netlify)
$indexOut = Join-Path $saida "index.html"
$html = [System.IO.File]::ReadAllText($indexOut)
if ($html -notmatch "__GOOGLE_PLACES_API_KEY__") {
    throw "Placeholder __GOOGLE_PLACES_API_KEY__ nao encontrado em index.html"
}
$html = $html.Replace("__GOOGLE_PLACES_API_KEY__", $ChaveGoogle)
[System.IO.File]::WriteAllText($indexOut, $html, (New-Object System.Text.UTF8Encoding($false)))

# .htaccess (Apache): sw.js/index.html/manifest sem cache longo, pra updates chegarem
$htaccess = @'
<IfModule mod_headers.c>
  <FilesMatch "^(index\.html|sw\.js|manifest\.json)$">
    Header set Cache-Control "no-cache, must-revalidate"
  </FilesMatch>
</IfModule>
<IfModule mod_mime.c>
  AddType application/manifest+json .json
  AddType application/javascript .js
</IfModule>
DirectoryIndex index.html
'@
[System.IO.File]::WriteAllText((Join-Path $saida ".htaccess"), $htaccess, (New-Object System.Text.UTF8Encoding($false)))

Write-Host "Pronto: $saida"
Get-ChildItem $saida -Recurse -File | ForEach-Object { Write-Host ("  " + $_.FullName.Substring($saida.Length + 1)) }
