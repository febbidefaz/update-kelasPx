<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SepController extends Controller
{
    private string $base;

    public function __construct()
    {
        // Lebih aman pakai config() daripada env() langsung (apalagi kalau config:cache)
        $this->base = rtrim(config('services.bpjs.base_url', env('BPJS_API_URL', '')), '/');
    }

    private function authorizeToken(string $token): void
    {
        if (!hash_equals((string) env('UPDATE_KELAS_TOKEN'), (string) $token)) {
            abort(403, 'Unauthorized');
        }
    }

    public function daftar()
    {
        $pasien = DB::connection('sqlsrv')
            ->select("SELECT * FROM dbo.DaftarPasienRawatInapSEP ORDER BY ID DESC");

        return view('pasien_inap', compact('pasien'));
    }

    public function updatekelas(string $nosep, string $token)
    {
        $this->authorizeToken($token);

        $url = $this->base . "/api/findsep?nosep=" . urlencode($nosep);
        $response = Http::timeout(8)->get($url);
        $json = $response->json();

        if (($json['metaData']['code'] ?? null) != 200) {
            return "SEP tidak ditemukan";
        }

        $kelas = (int) ($json['response']['klsRawat']['klsRawatHak'] ?? 0);
        if ($kelas <= 0) {
            return "Kelas hak kosong";
        }

        $register = DB::connection('sqlsrv')
            ->table('Therapy')
            ->where('NoSEP', $nosep)
            ->value('Register');

        if (!$register) {
            return "Register tidak ditemukan untuk SEP tsb";
        }

        DB::connection('sqlsrv')
            ->table('PasienList')
            ->where('RegNum', $register)
            ->update(['Plavon_kls' => $kelas]);

        return redirect('/daftar_pasien');
    }

    public function syncAutoPage(string $token)
    {
        $this->authorizeToken($token);
        return view('sync_kelas_auto', compact('token'));
    }

    public function syncRun(string $token): StreamedResponse
    {
        $this->authorizeToken($token);

        $base = $this->base; // penting: bawa ke scope closure

        return response()->stream(function () use ($base) {

            // Kalau server kamu masih buffering, ini bantu
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', 'off');
            @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) { @ob_end_flush(); }
            @ob_implicit_flush(true);

            $send = function (array $payload) {
                echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
            };

            $runId = now()->setTimezone('Asia/Jakarta')->format('Ymd_His');
            $logPath = storage_path("logs/sync_kelas_{$runId}.log");
            $fh = @fopen($logPath, 'a');

            $logLine = function (string $line) use ($fh) {
                $ts = now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                $row = "[{$ts}] {$line}";
                if ($fh) { @fwrite($fh, $row . PHP_EOL); }
                return $row;
            };

            // (opsional) filter biar tidak nembak API untuk data kosong
            $rows = DB::connection('sqlsrv')->select("
                SELECT *
                FROM dbo.DaftarPasienRawatInapSEP
                WHERE NoSEP IS NOT NULL AND LTRIM(RTRIM(NoSEP)) <> ''
                ORDER BY ID DESC
            ");

            $total = count($rows);

            $send([
                'type' => 'start',
                'runId' => $runId,
                'total' => $total,
                'logFile' => basename($logPath),
            ]);

            $logLine("START sync_kelas_bpjs total_pasien={$total} logFile=" . basename($logPath));

            $i = 0; $updated = 0; $same = 0; $failed = 0; $skipped = 0;

            foreach ($rows as $r) {
                $i++;

                $nosep = trim((string) $r->NoSEP);
                $reg   = trim((string) $r->RegNum);
                $nama  = (string) $r->Nama;
                $kelasLama = (int) ($r->Plavon_kls ?? 0);

                try {
                    $url = $base . "/api/findsep?nosep=" . urlencode($nosep);

                    $resp = Http::timeout(8)
                        ->retry(3, 200)
                        ->get($url);

                    $json = $resp->json();

                    if ((int)($json['metaData']['code'] ?? 0) !== 200) {
                        $skipped++;
                        $msg = $json['metaData']['message'] ?? 'API bukan 200';
                        $line = $logLine("SKIP reg={$reg} nama={$nama} nosep={$nosep} msg={$msg}");
                        $send(compact('i','total') + [
                            'type'=>'row','status'=>'skip','line'=>$line,
                            'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped
                        ]);
                        continue;
                    }

                    $kelasHak = (int) ($json['response']['klsRawat']['klsRawatHak'] ?? 0);
                    if ($kelasHak <= 0) {
                        $skipped++;
                        $line = $logLine("SKIP reg={$reg} nama={$nama} nosep={$nosep} msg=kelasHak_kosong");
                        $send(compact('i','total') + [
                            'type'=>'row','status'=>'skip','line'=>$line,
                            'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped
                        ]);
                        continue;
                    }

                    if ($kelasLama === $kelasHak) {
                        $same++;
                        $line = $logLine("SAME reg={$reg} nama={$nama} nosep={$nosep} kelas={$kelasHak}");
                        $send(compact('i','total') + [
                            'type'=>'row','status'=>'same','line'=>$line,
                            'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped
                        ]);
                        continue;
                    }

                    $aff = DB::connection('sqlsrv')
                        ->table('PasienList')
                        ->where('RegNum', $reg)
                        ->update(['Plavon_kls' => $kelasHak]);

                    if ($aff > 0) {
                        $updated++;
                        $line = $logLine("UPDATED reg={$reg} nama={$nama} nosep={$nosep} {$kelasLama}=>{$kelasHak}");
                        $send(compact('i','total') + [
                            'type'=>'row','status'=>'updated','line'=>$line,
                            'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped
                        ]);
                    } else {
                        $failed++;
                        $line = $logLine("FAIL reg={$reg} nama={$nama} nosep={$nosep} update_0rows {$kelasLama}=>{$kelasHak}");
                        $send(compact('i','total') + [
                            'type'=>'row','status'=>'fail','line'=>$line,
                            'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped
                        ]);
                    }

                } catch (\Throwable $e) {
                    $failed++;
                    $line = $logLine("ERROR reg={$reg} nama={$nama} nosep={$nosep} err=" . $e->getMessage());
                    $send(compact('i','total') + [
                        'type'=>'row','status'=>'fail','line'=>$line,
                        'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped
                    ]);
                }

                usleep(200 * 1000);
            }

            $logLine("DONE updated={$updated} same={$same} skipped={$skipped} failed={$failed}");
            if ($fh) { @fclose($fh); }

            $send([
                'type' => 'done',
                'total' => $total,
                'updated' => $updated,
                'same' => $same,
                'skipped' => $skipped,
                'failed' => $failed,
                'runId' => $runId,
                'logFile' => basename($logPath),
            ]);

        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // penting kalau pakai nginx
        ]);
    }
}