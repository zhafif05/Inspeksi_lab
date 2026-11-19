param(
    [string]$Base = 'http://localhost:8000/api',
    [string]$AdminEmail = 'admin@ppns.ac.id',
    [string]$AdminPassword = '',
    [string]$KalabEmail = '',
    [string]$KalabPassword = '',
    [string]$PlpEmail = '',
    [string]$PlpPassword = '',
    [string]$TeknisiEmail = '',
    [string]$TeknisiPassword = ''
)

$ErrorActionPreference = 'Stop'
$results = @()

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Path,
        [string]$Token,
        $Body
    )

    $headers = @{}
    if ($Token) { $headers.Authorization = "Bearer $Token" }

    $args = @{
        Method = $Method
        Uri = "$Base$Path"
        Headers = $headers
        ContentType = 'application/json'
        TimeoutSec = 30
    }
    if ($null -ne $Body) { $args.Body = ($Body | ConvertTo-Json -Depth 20) }

    try {
        $response = Invoke-WebRequest @args -UseBasicParsing
        $status = [int]$response.StatusCode
        $json = $response.Content | ConvertFrom-Json
    } catch {
        $status = [int]$_.Exception.Response.StatusCode
        $stream = $_.Exception.Response.GetResponseStream()
        $reader = [System.IO.StreamReader]::new($stream)
        $json = $reader.ReadToEnd() | ConvertFrom-Json
    }
    return [pscustomobject]@{ Status = $status; Json = $json }
}

function Check {
    param([string]$Name, [bool]$Ok, [string]$Detail = '')
    $script:results += [pscustomobject]@{ Name = $Name; Ok = $Ok; Detail = $Detail }
    $mark = if ($Ok) { 'PASS' } else { 'FAIL' }
    Write-Output "[$mark] $Name $Detail"
}

# 1. Health
$health = Invoke-Api -Method GET -Path '/health'
Check 'GET /health' ($health.Status -eq 200 -and $health.Json.status -eq 'OK') "status=$($health.Status)"

# 2. 401 tanpa token
$unauth = Invoke-Api -Method GET -Path '/labs'
Check 'GET /labs tanpa token -> 401' ($unauth.Status -eq 401) "status=$($unauth.Status) pesan=$($unauth.Json.message)"

# 3. Login per role dan endpoint representatif
$roles = @(
    @{ Name = 'admin'; Email = $AdminEmail; Password = $AdminPassword; Checks = @('/users','/labs','/items','/criteria/category/pending','/inspections/pending-reviews','/export/report-status') },
    @{ Name = 'kalab'; Email = $KalabEmail; Password = $KalabPassword; Checks = @('/labs','/items/my','/criteria/my','/inspections/my-pending') },
    @{ Name = 'plp'; Email = $PlpEmail; Password = $PlpPassword; Checks = @('/labs','/items/my','/criteria/my','/inspections/my-pending') },
    @{ Name = 'teknisi'; Email = $TeknisiEmail; Password = $TeknisiPassword; Checks = @('/labs','/items/my','/criteria/my','/inspections/my-pending') }
)

foreach ($role in $roles) {
    if (-not $role.Email -or -not $role.Password) {
        Check "Login $($role.Name)" $false 'kredensial tidak diberikan'
        continue
    }

    $login = Invoke-Api -Method POST -Path '/auth/login' -Body @{ email = $role.Email; password = $role.Password }
    Check "Login $($role.Name)" ($login.Status -eq 200 -and $login.Json.success -eq $true) "status=$($login.Status) pesan=$($login.Json.message)"
    if ($login.Status -ne 200) { continue }

    $token = $login.Json.data.token
    if (-not $token) {
        $token = $login.Json.token
    }
    Check "Token $($role.Name)" ([bool]$token) ''
    if (-not $token) { continue }

    foreach ($path in $role.Checks) {
        $res = Invoke-Api -Method GET -Path $path -Token $token
        Check "$($role.Name) GET $path" ($res.Json.success -eq $true) "status=$($res.Status)"
    }
}

$failed = @($results | Where-Object { -not $_.Ok })
Write-Output ''
Write-Output "TOTAL: $($results.Count), GAGAL: $($failed.Count)"
if ($failed.Count -gt 0) { exit 1 }
