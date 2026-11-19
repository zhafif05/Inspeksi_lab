param(
    [string]$Base = 'http://127.0.0.1:8000/api',
    [string]$AdminEmail = 'admin@ppns.ac.id',
    [string]$AdminPassword = 'Password123!',
    [string]$KalabEmail = 'kalab3@ppns.ac.id',
    [string]$KalabPassword = 'Password123!',
    [string]$PlpEmail = 'plp1@gmail.com',
    [string]$PlpPassword = 'Password123!',
    [string]$TeknisiEmail = 'teknisi1@gmail.com',
    [string]$TeknisiPassword = 'Password123!',
    [string]$UploadFile = ''
)

$ErrorActionPreference = 'Stop'
$script:results = @()
$script:suffix = [guid]::NewGuid().ToString('N').Substring(0, 8)

function Api {
    param(
        [Parameter(Mandatory = $true)][string]$Method,
        [Parameter(Mandatory = $true)][string]$Path,
        [string]$Token,
        $Body
    )

    $headers = @{}
    if ($Token) { $headers.Authorization = "Bearer $Token" }
    $requestParams = @{
        Method = $Method
        Uri = "$Base$Path"
        Headers = $headers
        ContentType = 'application/json'
        TimeoutSec = 60
    }
    if ($null -ne $Body) { $requestParams.Body = ($Body | ConvertTo-Json -Depth 30) }

    try {
        $response = Invoke-WebRequest @requestParams -UseBasicParsing
        $status = [int]$response.StatusCode
        $raw = $response.Content
    } catch {
        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
            $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
            $raw = $reader.ReadToEnd()
        } else {
            $status = 0
            $raw = $_.Exception.Message
        }
    }

    $json = $null
    try { $json = $raw | ConvertFrom-Json } catch { $json = $null }
    return [pscustomobject]@{ Status = $status; Json = $json; Raw = $raw }
}

function Check {
    param([string]$Name, [bool]$Ok, [string]$Detail = '')
    $script:results += [pscustomobject]@{ Name = $Name; Ok = $Ok; Detail = $Detail }
    $mark = if ($Ok) { 'PASS' } else { 'FAIL' }
    Write-Host "[$mark] $Name $Detail"
}

function Login {
    param([string]$Email, [string]$Password)
    $r = Api -Method POST -Path '/auth/login' -Body @{ email = $Email; password = $Password }
    if ($r.Status -ne 200) { Check "Login $Email" $false "status=$($r.Status)"; return $null }
    return $r.Json.data.token
}

function ResultIds {
    param($Detail, [int]$Month)
    $monthNode = @($Detail.Json.data.monthly_results | Where-Object { $_.bulan_ke -eq $Month })[0]
    if (-not $monthNode) { return @() }
    $ids = @()
    foreach ($cat in $monthNode.categories) { foreach ($item in $cat.items) { $ids += [int]$item.id } }
    # Koma menjaga array tetap bertipe array meski kosong (PowerShell meng-unroll return @()).
    return ,$ids
}

Write-Output '=== PENGUJIAN INTEGRASI LARAVEL ==='

# --- Autentikasi & contract error ---
$adminToken = Login $AdminEmail $AdminPassword
$kalabToken = Login $KalabEmail $KalabPassword
$plpToken = Login $PlpEmail $PlpPassword
$teknisiToken = Login $TeknisiEmail $TeknisiPassword
Check 'Login keempat role' ($adminToken -and $kalabToken -and $plpToken -and $teknisiToken) ''

$profile = Api -Method GET -Path '/auth/profile' -Token $adminToken
Check 'GET /auth/profile' ($profile.Status -eq 200 -and $profile.Json.data.email -eq $AdminEmail) "status=$($profile.Status)"

$badToken = Api -Method GET -Path '/labs' -Token 'token.tidak.valid'
Check 'Token invalid -> 403 (perilaku lama)' ($badToken.Status -eq 403 -and $badToken.Json.message -eq 'Token tidak valid atau kadaluarsa') "status=$($badToken.Status)"

$invalid = Api -Method POST -Path '/auth/login' -Body @{ email = 'bukan-email'; password = '' }
$emailErr = @($invalid.Json.errors | Where-Object { $_.path -eq 'email' })[0]
$passErr = @($invalid.Json.errors | Where-Object { $_.path -eq 'password' })[0]
Check 'Validation error express-validator shape' (
    $invalid.Status -eq 400 -and
    $invalid.Json.message -eq 'Validasi input gagal' -and
    $emailErr.type -eq 'field' -and $emailErr.msg -eq 'Email tidak valid' -and $emailErr.location -eq 'body' -and
    $passErr.msg -eq 'Password harus diisi'
) "status=$($invalid.Status)"

# --- Pembatasan role ---
Check 'plp dilarang POST /users' ((Api -Method POST -Path '/users' -Token $plpToken -Body @{name='x';email='x@y.z';password='x';role='admin'}).Status -eq 403) ''
Check 'teknisi dilarang POST /labs' ((Api -Method POST -Path '/labs' -Token $teknisiToken -Body @{nama_lab='x';lokasi='x'}).Status -eq 403) ''
Check 'kalab dilarang bulk-approve criteria' ((Api -Method PUT -Path '/criteria/categories/bulk-approve' -Token $kalabToken -Body @{ids=@(1)}).Status -eq 403) ''

# --- CRUD users ---
$userEmail = "uji.$script:suffix@example.com"
$newUser = Api -Method POST -Path '/users' -Token $adminToken -Body @{
    name = 'User Uji Integrasi'; nip = "N$script:suffix"; email = $userEmail; password = 'Password123!'; role = 'teknisi'
}
$userId = $newUser.Json.data.id
Check 'POST /users' ($newUser.Status -eq 201 -and $userId -gt 0) "status=$($newUser.Status)"
Check 'POST /users duplikat email' ((Api -Method POST -Path '/users' -Token $adminToken -Body @{name='x';email=$userEmail;password='x';role='teknisi'}).Status -eq 400) ''
$updUser = Api -Method PUT -Path "/users/$userId" -Token $adminToken -Body @{name='User Uji Diubah';nip='N-UJI';email=$userEmail;role='teknisi'}
Check 'PUT /users/{id}' ($updUser.Status -eq 200) "status=$($updUser.Status)"

# --- CRUD labs ---
$newLab = Api -Method POST -Path '/labs' -Token $adminToken -Body @{nama_lab="LAB UJI $script:suffix";lokasi='Lokasi Uji'}
$labId = $newLab.Json.data.id
Check 'POST /labs' ($newLab.Status -eq 201 -and $labId -gt 0) "status=$($newLab.Status)"
$updLab = Api -Method PUT -Path "/labs/$labId" -Token $adminToken -Body @{nama_lab="LAB UJI UPDATE $script:suffix";lokasi='Lokasi Uji 2'}
Check 'PUT /labs/{id}' ($updLab.Status -eq 200) "status=$($updLab.Status)"
Check 'GET /labs' ((Api -Method GET -Path '/labs' -Token $adminToken).Status -eq 200) ''

# --- CRUD items ---
$itemCode = "UJI-$script:suffix"
$newItem = Api -Method POST -Path '/items' -Token $adminToken -Body @{
    nama_barang = 'Barang Uji Integrasi'; kode_barang = $itemCode; pembuat_alat = 'Uji';
    tanggal_pembelian = '2024-01-10'; laboratory_id = 11;
    tanggal_kalibrasi_terakhir = '2024-01-10'; tanggal_kalibrasi_berikutnya = '2025-01-10'
}
$itemId = $newItem.Json.data.id
Check 'POST /items' ($newItem.Status -eq 201 -and $itemId -gt 0) "status=$($newItem.Status)"
Check 'POST /items tanpa kode_barang' ((Api -Method POST -Path '/items' -Token $adminToken -Body @{nama_barang='x'}).Status -eq 400) ''
Check 'GET /items/{id}' ((Api -Method GET -Path "/items/$itemId" -Token $adminToken).Status -eq 200) ''
Check 'GET /items/lab/11' ((Api -Method GET -Path '/items/lab/11' -Token $adminToken).Status -eq 200) ''
Check 'GET /items/calibration-alerts' ((Api -Method GET -Path '/items/calibration-alerts' -Token $adminToken).Status -eq 200) ''
$updItem = Api -Method PUT -Path "/items/$itemId" -Token $adminToken -Body @{
    nama_barang = 'Barang Uji Diubah'; kode_barang = $itemCode; pembuat_alat = 'Uji 2';
    tanggal_pembelian = '2024-01-11'; tanggal_kalibrasi_terakhir = '2024-01-11'; tanggal_kalibrasi_berikutnya = '2025-01-11'
}
Check 'PUT /items/{id}' ($updItem.Status -eq 200) "status=$($updItem.Status)"

# --- CRUD schedules ---
$newSched = Api -Method POST -Path '/schedules' -Token $adminToken -Body @{laboratory_id=11;tanggal='2024-09-05';keterangan="Jadwal Uji $script:suffix"}
$schedId = $newSched.Json.data.id
Check 'POST /schedules' ($newSched.Status -eq 201 -and $schedId -gt 0) "status=$($newSched.Status)"
Check 'POST /schedules validasi' ((Api -Method POST -Path '/schedules' -Token $adminToken -Body @{laboratory_id='abc';tanggal='';keterangan=''}).Json.message -eq 'Validasi input gagal') ''
Check 'GET /schedules/lab/11' ((Api -Method GET -Path '/schedules/lab/11' -Token $adminToken).Status -eq 200) ''
Check 'PUT /schedules/{id}' ((Api -Method PUT -Path "/schedules/$schedId" -Token $adminToken -Body @{laboratory_id=11;tanggal='2024-09-06';keterangan='Jadwal Uji Diubah'}).Status -eq 200) ''
Check 'DELETE /schedules/{id}' ((Api -Method DELETE -Path "/schedules/$schedId" -Token $adminToken).Status -eq 200) ''

# --- Criteria: buat PENDING -> blok inspeksi -> approve ---
$catName = "Kategori Uji $script:suffix"
$newCat = Api -Method POST -Path '/criteria/category-with-subitems/create' -Token $plpToken -Body @{
    item_id = $itemId; laboratory_id = 11;
    categories = @(@{
        nama_kategori = $catName; deskripsi = 'Kategori uji integrasi'; urutan = 1;
        subitems = @(@{nama_subitem='Sub-item Uji 1';deskripsi='Sub uji';urutan=1})
    })
}
Check 'POST criteria/category-with-subitems/create' ($newCat.Status -eq 201 -and $newCat.Json.total -eq 1) "status=$($newCat.Status)"
$badNested = Api -Method POST -Path '/criteria/category-with-subitems/create' -Token $plpToken -Body @{
    categories = @(@{nama_kategori='';urutan=0;subitems=@()})
}
$nestedPathOk = @($badNested.Json.errors | Where-Object { $_.path -eq 'categories[0].nama_kategori' }).Count -eq 1
Check 'Nested validation path bracket' $nestedPathOk "status=$($badNested.Status)"

$pendingCats = Api -Method GET -Path '/criteria/category/pending' -Token $adminToken
$myCat = @($pendingCats.Json.data | Where-Object { $_.nama_kategori -eq $catName })[0]
Check 'GET /criteria/category/pending' ([bool]$myCat) "total=$($pendingCats.Json.total)"
$categoryId = if ($myCat) { [int]$myCat.id } else { 0 }

$pendingSubs = Api -Method GET -Path '/criteria/subitem/pending' -Token $adminToken
$mySub = @($pendingSubs.Json.data | Where-Object { $_.category_id -eq $categoryId })[0]
$subitemId = if ($mySub) { [int]$mySub.id } else { 0 }
Check 'GET /criteria/subitem/pending' ($subitemId -gt 0) ''

$blocked = Api -Method POST -Path '/inspections/create' -Token $adminToken -Body @{
    item_id = $itemId; laboratory_id = 11; tahun = 2024; semester = 'GANJIL';
    tanggal_inspeksi = '2024-09-01 08:00:00'; catatan = 'Uji integrasi'
}
Check 'Inspeksi diblokir saat kriteria PENDING' ($blocked.Status -eq 400 -and $blocked.Json.message -like '*PENDING atau REJECTED*') "status=$($blocked.Status)"

Check 'Admin approve subitem' ((Api -Method PUT -Path "/criteria/subitem/$subitemId/approve" -Token $adminToken).Status -eq 200) ''
Check 'Admin approve kategori' ((Api -Method PUT -Path "/criteria/category/$categoryId/approve" -Token $adminToken).Status -eq 200) ''
Check 'GET /criteria/approved' ((Api -Method GET -Path '/criteria/approved' -Token $adminToken).Status -eq 200) ''
Check 'GET /criteria/item/{id}' ((Api -Method GET -Path "/criteria/item/$itemId" -Token $adminToken).Status -eq 200) ''

# --- Alur inspeksi 6 bulan ---
# Fallback lama memasukkan NULL eksplisit ke kolom status NOT NULL; pada MariaDB
# lokal ini menghasilkan 500 seperti aplikasi Express source. Pertahankan.
$autoCreated = Api -Method POST -Path '/inspections/create' -Token $adminToken -Body @{
    item_id = $itemId; laboratory_id = 11; tahun = 2024; semester = 'GANJIL';
    tanggal_inspeksi = '2024-09-01 08:00:00'; catatan = 'Uji integrasi'
}
Check 'POST /inspections/create fallback NULL (perilaku lama)' (
    $autoCreated.Status -eq 500 -and $autoCreated.Json.message -like "*status*cannot be null*"
) "status=$($autoCreated.Status)"

# Jalur normal UI mengirim checklist_results eksplisit.
$created = Api -Method POST -Path '/inspections/create' -Token $adminToken -Body @{
    item_id = $itemId; laboratory_id = 11; tahun = 2024; semester = 'GANJIL';
    tanggal_inspeksi = '2024-09-01 08:00:00'; catatan = 'Uji integrasi';
    checklist_results = @(@{subitem_id=[int]$mySub.id;status='B';keterangan='Uji';bulan_ke=1})
}
$inspectionId = $created.Json.data.id
Check 'POST /inspections/create (checklist eksplisit)' ($created.Status -eq 201 -and $inspectionId -gt 0) "status=$($created.Status), results=$($created.Json.data.total_results)"
Check 'POST /inspections/create duplikat' ((Api -Method POST -Path '/inspections/create' -Token $adminToken -Body @{item_id=$itemId;laboratory_id=11;tahun=2024;semester='GANJIL'}).Status -eq 400) ''

$detail = Api -Method GET -Path "/inspections/detail/$inspectionId" -Token $adminToken
$month1Ids = ResultIds $detail 1
Check 'GET /inspections/detail/{id}' ($detail.Status -eq 200 -and $month1Ids.Count -gt 0) "bulan1=$($month1Ids.Count)"
$result1Id = $month1Ids[0]

$gate = Api -Method PUT -Path "/inspections/$inspectionId/results" -Token $adminToken -Body @{
    bulan_ke = 2; results = @(@{id=$result1Id;status='B';keterangan='Uji'})
}
Check 'Bulan 2 diblokir sebelum bulan 1 disetujui' ($gate.Status -eq 400 -and $gate.Json.message -like '*belum disetujui*') "status=$($gate.Status)"

$rejectEmpty = Api -Method PUT -Path "/inspections/$inspectionId/reject-month/1" -Token $adminToken -Body @{}
Check 'Reject tanpa alasan -> validasi' ($rejectEmpty.Json.message -eq 'Validasi input gagal') ''
$rejectMonth = Api -Method PUT -Path "/inspections/$inspectionId/reject-month/1" -Token $adminToken -Body @{alasan_penolakan='Uji penolakan'}
Check 'Reject bulan 1' ($rejectMonth.Status -eq 200) "status=$($rejectMonth.Status)"

$resubmit = Api -Method PUT -Path "/inspections/result/$result1Id" -Token $adminToken -Body @{status='K';keterangan='Diperbaiki'}
Check 'Update hasil setelah reject -> resubmitted' ($resubmit.Status -eq 200 -and $resubmit.Json.resubmitted -eq $true) "status=$($resubmit.Status)"

$approveResult = Api -Method PUT -Path "/inspections/result/$result1Id/approve" -Token $adminToken
Check 'Admin approve per-item' ($approveResult.Status -eq 200) "status=$($approveResult.Status)"
Check 'plp dilarang approve per-item' ((Api -Method PUT -Path "/inspections/result/$result1Id/approve" -Token $plpToken).Status -eq 403) ''

$approveMonth = Api -Method PUT -Path "/inspections/$inspectionId/approve-month/1" -Token $adminToken
Check 'Approve per-item mengunci review bulan 1 (perilaku lama)' (
    $approveMonth.Status -eq 400 -and
    $approveMonth.Json.message -eq 'Review bulan ke-1 sudah memiliki status: APPROVED'
) "status=$($approveMonth.Status)"
Check 'Approve bulan 1 dua kali -> 400' ((Api -Method PUT -Path "/inspections/$inspectionId/approve-month/1" -Token $adminToken).Status -eq 400) ''

$month2 = Api -Method PUT -Path "/inspections/$inspectionId/results" -Token $adminToken -Body @{
    bulan_ke = 2; results = @(@{subitem_id=$mySub.id;status='K';keterangan='Rusak saat uji'})
}
Check 'Isi hasil bulan 2' ($month2.Status -eq 200) "status=$($month2.Status)"
$detail = Api -Method GET -Path "/inspections/detail/$inspectionId" -Token $adminToken
$month2Ids = ResultIds $detail 2
$result2Id = $month2Ids[0]
Check 'Hasil bulan 2 tersimpan' ($month2Ids.Count -gt 0) "id=$result2Id"

$damage = Api -Method POST -Path '/damage-reports' -Token $adminToken -Body @{
    inspection_result_id = $result2Id; inspection_id = $inspectionId; laboratory_id = 11;
    item_id = $itemId; category_id = $categoryId; subitem_id = $mySub.id;
    kondisi_kerusakan = 'Rusak'; penyebab_kerusakan = 'Uji'; status_peralatan = 'Perlu perbaikan'
}
Check 'POST /damage-reports' ($damage.Status -in @(200,201)) "status=$($damage.Status)"
Check 'GET /damage-reports/result/{id}' ((Api -Method GET -Path "/damage-reports/result/$result2Id" -Token $adminToken).Status -eq 200) ''
Check 'DELETE /damage-reports/result/{id}' ((Api -Method DELETE -Path "/damage-reports/result/$result2Id" -Token $adminToken).Status -eq 200) ''

Check 'Admin approve bulan 2' ((Api -Method PUT -Path "/inspections/$inspectionId/approve-month/2" -Token $adminToken).Status -eq 200) ''

$month3 = Api -Method PUT -Path "/inspections/$inspectionId/results" -Token $adminToken -Body @{
    bulan_ke = 3; results = @(@{subitem_id=$mySub.id;status='B';keterangan='Baik'})
}
Check 'Isi hasil bulan 3' ($month3.Status -eq 200) "status=$($month3.Status)"
$detail = Api -Method GET -Path "/inspections/detail/$inspectionId" -Token $adminToken
$result3Id = (ResultIds $detail 3)[0]
$bulk = Api -Method PUT -Path '/inspections/results/bulk-approve' -Token $adminToken -Body @{ids=@($result3Id)}
Check 'Admin bulk-approve results' ($bulk.Status -eq 200) "status=$($bulk.Status)"
Check 'plp dilarang bulk-approve results' ((Api -Method PUT -Path '/inspections/results/bulk-approve' -Token $plpToken -Body @{ids=@($result3Id)}).Status -eq 403) ''
$approve3AfterBulk = Api -Method PUT -Path "/inspections/$inspectionId/approve-month/3" -Token $adminToken
Check 'Bulk approve mengunci review bulan 3 (perilaku lama)' (
    $approve3AfterBulk.Status -eq 400 -and
    $approve3AfterBulk.Json.message -eq 'Review bulan ke-3 sudah memiliki status: APPROVED'
) "status=$($approve3AfterBulk.Status)"
Check 'GET /inspections/pending-reviews' ((Api -Method GET -Path '/inspections/pending-reviews' -Token $adminToken).Status -eq 200) ''
Check 'GET /inspections/results/status' ((Api -Method GET -Path '/inspections/results/status' -Token $adminToken).Status -eq 200) ''
Check 'GET /inspections/month/3' ((Api -Method GET -Path '/inspections/month/3' -Token $adminToken).Status -eq 200) ''
$labStatus = Api -Method GET -Path '/inspections/check-lab-status?labId=10&tahun=2026&semester=GENAP' -Token $adminToken
Check 'GET /inspections/check-lab-status' ($labStatus.Status -eq 200 -and $labStatus.Json.canExport -eq $true) "status=$($labStatus.Status)"
Check 'GET /inspections/by-item/{id}' ((Api -Method GET -Path "/inspections/by-item/$itemId" -Token $adminToken).Status -eq 200) ''

# --- Export ---
function Download {
    param([string]$Path, [string]$Token)
    try {
        $r = Invoke-WebRequest -Uri "$Base$Path" -Headers @{Authorization="Bearer $Token"} -UseBasicParsing -TimeoutSec 90
        return [pscustomobject]@{ Status = [int]$r.StatusCode; Bytes = $r.RawContentLength }
    } catch {
        return [pscustomobject]@{ Status = 0; Bytes = 0 }
    }
}
$ex1 = Download "/inspections/export/$inspectionId" $adminToken
Check "GET /inspections/export/{id}" ($ex1.Status -eq 200 -and $ex1.Bytes -gt 0) "status=$($ex1.Status), bytes=$($ex1.Bytes)"
$ex2 = Download '/inspections/export-all?tahun=2024&semester=GANJIL' $adminToken
Check 'GET /inspections/export-all' ($ex2.Status -eq 200 -and $ex2.Bytes -gt 0) "status=$($ex2.Status), bytes=$($ex2.Bytes)"
$ex3 = Download '/inspections/export-lab?labId=10&tahun=2026&semester=GENAP' $adminToken
Check 'GET /inspections/export-lab' ($ex3.Status -eq 200 -and $ex3.Bytes -gt 0) "status=$($ex3.Status), bytes=$($ex3.Bytes)"
$ex4 = Download '/export/laporan-peralatan-rusak?tahun=2026&semester=GENAP' $adminToken
Check 'GET /export/laporan-peralatan-rusak' ($ex4.Status -eq 200 -and $ex4.Bytes -gt 0) "status=$($ex4.Status), bytes=$($ex4.Bytes)"
$ex5 = Download '/export/repair-equipment?tahun=2026&semester=GENAP' $adminToken
Check 'GET /export/repair-equipment' ($ex5.Status -eq 200 -and $ex5.Bytes -gt 0) "status=$($ex5.Status), bytes=$($ex5.Bytes)"
$ex6 = Api -Method GET -Path '/export/report-status?tahun=2024&semester=GANJIL' -Token $adminToken
Check 'GET /export/report-status' ($ex6.Status -eq 200 -and $ex6.Json.success -eq $true) "status=$($ex6.Status)"

# --- Upload lokal & filter MIME Drive ---
if (-not $UploadFile) {
    $UploadFile = Join-Path $env:TEMP "uji-upload-$script:suffix.txt"
    Set-Content -Path $UploadFile -Value 'Uji integrasi upload' -Encoding UTF8
}
$uploadOut = & curl.exe -s -o NUL -w '%{http_code}' -F "file=@$UploadFile" -F "lab_id=11" "$Base/upload/lab" 2>$null
Check 'POST /upload/lab' ($uploadOut -eq '200') "status=$uploadOut"
$driveOut = & curl.exe -s -F "file=@$UploadFile" -F "lab_id=11" "$Base/upload/lab/drive" 2>$null
Check 'POST /upload/lab/drive tolak non-PDF (500)' ($driveOut -like '*Hanya file PDF yang diizinkan*') "resp=$driveOut"

# --- Bersihkan data uji ---
foreach ($cleanup in @(
    @{ Name = 'DELETE inspection'; Method = 'DELETE'; Path = "/inspections/$inspectionId" },
    @{ Name = 'DELETE category'; Method = 'DELETE'; Path = "/criteria/category/$categoryId" },
    @{ Name = 'DELETE item'; Method = 'DELETE'; Path = "/items/$itemId" },
    @{ Name = 'DELETE lab uji'; Method = 'DELETE'; Path = "/labs/$labId" },
    @{ Name = 'DELETE user uji'; Method = 'DELETE'; Path = "/users/$userId" }
)) {
    $r = Api -Method $cleanup.Method -Path $cleanup.Path -Token $adminToken
    Check $cleanup.Name ($r.Status -eq 200) "status=$($r.Status)"
}

$failed = @($script:results | Where-Object { -not $_.Ok })
Write-Output ''
Write-Output "TOTAL: $($script:results.Count), GAGAL: $($failed.Count)"
if ($failed.Count -gt 0) {
    Write-Output 'Rincian kegagalan:'
    $failed | ForEach-Object { Write-Output "- $($_.Name) $($_.Detail)" }
    exit 1
}
