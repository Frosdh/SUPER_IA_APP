param(
  [string]$DeviceId = ""
)

$ErrorActionPreference = "Stop"

function Get-FirstOnlineDevice {
  $lines = & adb devices 2>$null
  if (-not $lines) { return "" }

  foreach ($line in $lines) {
    if ($line -match "^([A-Za-z0-9._:-]+)\s+device$") {
      return $Matches[1]
    }
  }
  return ""
}

if (-not $DeviceId) {
  $DeviceId = Get-FirstOnlineDevice
}

if (-not $DeviceId) {
  Write-Host "No se encontró un dispositivo ADB en estado 'device'."
  Write-Host "Ejecuta: adb devices"
  exit 1
}

Write-Host "Usando dispositivo: $DeviceId"

# Túnel USB: el teléfono (127.0.0.1:8080) -> PC (localhost:80)
Write-Host "Configurando adb reverse tcp:8080 -> tcp:80"
& adb -s $DeviceId reverse tcp:8080 tcp:80

$apiBase = "http://127.0.0.1:8080/SUPER_IA/server_php"
Write-Host "Ejecutando Flutter con API_BASE_URL=$apiBase"

& flutter run -d $DeviceId --dart-define=API_BASE_URL=$apiBase
