# Gera as pastas android/ e ios/ (o código em lib/ não é alterado) e acrescenta as permissões da app.
# Uso (na pasta mobile, com o Flutter instalado):  powershell -ExecutionPolicy Bypass -File tool\setup_platforms.ps1
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

flutter create . --org ao.longa --project-name longa --platforms android,ios

# Android: internet, câmara (QR e foto), localização (pedido de aula); HTTP sem TLS só para testes locais
$manifest = 'android/app/src/main/AndroidManifest.xml'
$xml = Get-Content $manifest -Raw
$permissions = @(
    '<uses-permission android:name="android.permission.INTERNET"/>',
    '<uses-permission android:name="android.permission.CAMERA"/>',
    '<uses-permission android:name="android.permission.ACCESS_FINE_LOCATION"/>',
    '<uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION"/>'
)
foreach ($p in $permissions) {
    if ($xml -notlike "*$p*") { $xml = $xml -replace '<application', "$p`n    <application" }
}
if ($xml -notlike '*usesCleartextTraffic*') {
    $xml = $xml -replace '<application', '<application android:usesCleartextTraffic="true"'
}
$xml = $xml -replace 'android:label="longa"', 'android:label="LONGA"'
Set-Content $manifest $xml -Encoding UTF8

# iOS: textos dos pedidos de permissão
$plist = 'ios/Runner/Info.plist'
$content = Get-Content $plist -Raw
$keys = [ordered]@{
    'NSCameraUsageDescription' = 'A câmara é usada para ler o QR de confirmação da aula e tirar a foto de perfil.'
    'NSLocationWhenInUseUsageDescription' = 'A localização marca o local da aula e encontra formadores perto de si.'
    'NSPhotoLibraryUsageDescription' = 'Escolha uma foto de perfil ou evidências para uma disputa.'
}
foreach ($k in $keys.Keys) {
    if ($content -notlike "*$k*") { $content = $content -replace '</dict>\s*</plist>', "  <key>$k</key>`n  <string>$($keys[$k])</string>`n</dict>`n</plist>" }
}
Set-Content $plist $content -Encoding UTF8

flutter pub get
Write-Host 'Pronto. Ligue o telemóvel (depuração USB) e corra: flutter run --dart-define=API_URL=http://IP-DO-PC:8000 --dart-define=REVERB_HOST=IP-DO-PC'
