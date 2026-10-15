<?php

namespace Tests\Feature;

use App\Mail\BackupFailedMail;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackupDriveCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('backup.google.client_id', 'client-id');
        Config::set('backup.google.client_secret', 'client-secret');
        Config::set('backup.google.refresh_token', 'refresh-token');
        Config::set('backup.google.folder_id', 'folder-123');
        Config::set('backup.prefix', 'seolinkbuildings-backup');
        Config::set('backup.keep', 30);
        Config::set('backup.media_roots', []);
        Config::set('mail.admin_email', 'henrybrown1930@gmail.com');
        Mail::fake();
    }

    public function test_backup_drive_is_scheduled_at_three_am_karachi(): void
    {
        $this->artisan('schedule:list')->assertSuccessful();

        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'backup:drive'));

        $this->assertNotNull($scheduled);
        $this->assertSame('0 3 * * *', $scheduled->expression);
        $this->assertSame('Asia/Karachi', $scheduled->timezone);
    }

    public function test_test_flag_verifies_credentials_without_uploading(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test',
                'expires_in' => 3600,
            ], 200),
            'https://www.googleapis.com/drive/v3/files/folder-123*' => Http::response([
                'id' => 'folder-123',
                'name' => 'seolinkbuildings-backups',
                'mimeType' => 'application/vnd.google-apps.folder',
            ], 200),
        ]);

        $this->artisan('backup:drive', ['--test' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Google Drive credentials ok');

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'uploadType=resumable'));
    }

    public function test_backup_uploads_zip_and_prunes_old_prefixed_files(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600], 200);
            }
            if ($request->method() === 'POST' && str_contains($url, 'uploadType=resumable')) {
                return Http::response('', 200, [
                    'Location' => 'https://www.googleapis.com/upload/drive/v3/files?upload_id=abc',
                ]);
            }
            if ($request->method() === 'PUT' && str_contains($url, 'upload_id=abc')) {
                return Http::response(['id' => 'file-new'], 200);
            }
            // List is GET /drive/v3/files?q=...'folder-123' in parents...
            // Folder metadata is GET /drive/v3/files/folder-123
            if ($request->method() === 'GET' && str_contains($url, '/drive/v3/files')) {
                if (preg_match('#/drive/v3/files/folder-123(\?|$)#', $url)) {
                    return Http::response([
                        'id' => 'folder-123',
                        'name' => 'seolinkbuildings-backups',
                        'mimeType' => 'application/vnd.google-apps.folder',
                    ], 200);
                }
                $old = [];
                for ($i = 1; $i <= 31; $i++) {
                    $old[] = [
                        'id' => 'old-'.$i,
                        'name' => 'seolinkbuildings-backup-2020010'.$i.'.zip',
                        'createdTime' => sprintf('2020-01-%02dT00:00:00.000Z', min($i, 28)),
                    ];
                }

                return Http::response(['files' => $old], 200);
            }
            if ($request->method() === 'DELETE') {
                return Http::response('', 204);
            }

            return Http::response(['error' => 'unexpected '.$request->method().' '.$url], 500);
        });

        $this->artisan('backup:drive')
            ->expectsOutputToContain('Uploaded file id')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'uploadType=resumable'));
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), 'upload_id=abc'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/drive/v3/files/old-1'));
        Mail::assertNothingSent();
        $this->assertFalse(is_dir(storage_path('app/backup-tmp')) && count(glob(storage_path('app/backup-tmp/*')) ?: []) > 0);
    }

    public function test_failure_emails_admin(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->artisan('backup:drive')->assertFailed();

        Mail::assertSent(BackupFailedMail::class, function (BackupFailedMail $mail) {
            return $mail->hasTo('henrybrown1930@gmail.com')
                && str_contains($mail->errorMessage, 'token refresh failed');
        });
    }

    public function test_php_dumper_writes_sql_for_sqlite(): void
    {
        Schema::create('backup_probe', function ($table) {
            $table->id();
            $table->string('name');
        });
        DB::table('backup_probe')->insert(['name' => 'hello']);

        $path = storage_path('app/backup-tmp/probe.sql');
        app(DatabaseDumper::class)->dumpTo($path);

        $sql = (string) file_get_contents($path);
        $this->assertStringContainsString('backup_probe', $sql);
        $this->assertStringContainsString('hello', $sql);
        @unlink($path);
    }
}
