<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Legacy;
use App\Support\LegacyUpload;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadController extends Controller
{
    public function uploadLabReport(Request $request)
    {
        $uploadedPath = null;

        try {
            $file = $this->firstUploadedFile($request);
            if (!$file) {
                return Api::error('No file uploaded', 400);
            }

            // Multer menyimpan file sebelum controller dipanggil, jadi lakukan juga di sini.
            $uploadedPath = Legacy::saveUpload($file, 'file');

            $labId = $request->input('lab_id');
            if (!$labId) {
                return Api::error('Lab ID wajib diisi', 400);
            }

            $lab = DB::table('laboratories')->where('id', $labId)->first();
            if (!$lab) {
                return Api::error('Laboratorium tidak ditemukan', 404);
            }

            $oldReport = $lab->report_file;

            DB::table('laboratories')->where('id', $labId)->update([
                'report_file' => $uploadedPath,
            ]);

            if ($oldReport) {
                $oldFile = Legacy::uploadPath(basename($oldReport));
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            return Api::ok([
                'url' => $uploadedPath,
                'message' => 'Upload berhasil',
            ]);
        } catch (\Throwable $e) {
            // Rollback file baru jika terjadi error, seperti blok catch di source.
            if ($uploadedPath) {
                $newFile = Legacy::uploadPath(basename($uploadedPath));
                if (is_file($newFile)) {
                    @unlink($newFile);
                }
            }

            throw $e;
        }
    }

    public function uploadLabReportToDrive(Request $request)
    {
        if ($error = LegacyUpload::validatePdf($request)) {
            return $error;
        }

        $file = $this->firstUploadedFile($request);
        if (!$file) {
            return Api::error('No file uploaded', 400);
        }

        $mimeType = $file->getClientMimeType();

        $labId = $request->input('lab_id');
        if (!$labId) {
            return Api::error('Lab ID wajib diisi', 400);
        }

        $lab = DB::table('laboratories')->where('id', $labId)->first();
        if (!$lab) {
            return Api::error('Laboratorium tidak ditemukan', 404);
        }

        $client = new GoogleClient();
        $client->setAuthConfig(storage_path('app/credentials/sim-inspeksi-ppns-69f360b30428.json'));
        $client->addScope(GoogleDrive::DRIVE);
        $drive = new GoogleDrive($client);

        $metadata = new DriveFile([
            'name' => $file->getClientOriginalName(),
            'parents' => [env('GOOGLE_DRIVE_FOLDER_ID')],
        ]);

        $created = $drive->files->create($metadata, [
            'data' => file_get_contents($file->getRealPath()),
            'mimeType' => $mimeType,
            'uploadType' => 'media',
            'fields' => 'id',
        ]);

        $fileId = $created->getId();

        $drive->permissions->create($fileId, new Permission([
            'role' => 'reader',
            'type' => 'anyone',
        ]));

        $remoteFile = $drive->files->get($fileId, ['fields' => 'webViewLink']);
        $url = $remoteFile->getWebViewLink();

        DB::table('laboratories')->where('id', $labId)->update([
            'report_file' => $url,
        ]);

        return Api::ok([
            'url' => $url,
            'message' => 'Upload berhasil ke Google Drive',
        ]);
    }

    /** Ambil file pada field "file" (nama field di route lama), dengan fallback. */
    private function firstUploadedFile(Request $request): ?UploadedFile
    {
        $file = $request->file('file');
        if ($file instanceof UploadedFile) {
            return $file;
        }

        foreach ($request->allFiles() as $uploaded) {
            if (is_array($uploaded)) {
                $uploaded = reset($uploaded);
            }
            if ($uploaded instanceof UploadedFile) {
                return $uploaded;
            }
        }

        return null;
    }
}
