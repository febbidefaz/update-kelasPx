<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$base = env('BPJS_API_URL');
class SepController extends Controller
{

    public function daftar()
    {
        $pasien = DB::connection('sqlsrv')
        ->select("SELECT * FROM dbo.DaftarPasienRawatInapSEP ORDER BY ID DESC");

        return view('pasien_inap', compact('pasien'));
    }

    public function updatekelas($nosep, $token)
    {
    
        if ($token !== env('UPDATE_KELAS_TOKEN')) {
            abort(403, 'Unauthorized');
        }
        
        $url  = $base."/api/findsep?nosep=".$nosep;
           
        $response = Http::get($url);
    
        if ($response->json()['metaData']['code'] != 200) {
            return "SEP tidak ditemukan";
        }
    
        $kelas = $response->json()['response']['klsRawat']['klsRawatHak'];
    
        $register = DB::connection('sqlsrv')
            ->table('Therapy')
            ->where('NoSEP',$nosep)
            ->value('Register');
    
        DB::connection('sqlsrv')
            ->table('PasienList')
            ->where('RegNum',$register)
            ->update([
                'Plavon_kls'=>$kelas
            ]);
    
        return redirect('/daftar_pasien');
    }

    public function syncAutoPage($token)
    {
        if ($token !== env('UPDATE_KELAS_TOKEN')) {
            abort(403, 'Unauthorized');
        }
    
        return view('sync_kelas_auto', compact('token'));
    }
    
    public function syncRun($token)
    {
        if ($token !== env('UPDATE_KELAS_TOKEN')) {
            abort(403, 'Unauthorized');
        }
    
        return response()->stream(function () {
    
            $send = function (array $payload) {
                echo "data: " . json_encode($payload) . "\n\n";
                @ob_flush(); @flush();
            };
    
            // runId untuk penamaan log file
            $runId = now()->setTimezone('Asia/Jakarta')->format('Ymd_His');
            $logPath = storage_path("logs/sync_kelas_{$runId}.log");
            $fh = @fopen($logPath, 'a');
    
            $logLine = function (string $line) use ($fh) {
                $ts = now()->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s');
                $row = "[{$ts}] {$line}";
                if ($fh) { @fwrite($fh, $row . PHP_EOL); }
                return $row;
            };
    
            // ambil pasien rawat inap dari VIEW (filter NoSEP valid + prefix)
            $rows = DB::connection('sqlsrv')->select("
            SELECT * FROM dbo.DaftarPasienRawatInapSEP ORDER BY ID DESC
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
    
                $nosep = trim((string)$r->NoSEP);
                $reg   = trim((string)$r->RegNum);
                $nama  = (string)$r->Nama;
                $kelasLama = (int)($r->Plavon_kls ?? 0);
    
                try {
                    $url = $base."/api/findsep?nosep=" . urlencode($nosep);
                    $resp = Http::timeout(8)
                                ->retry(3, 200)
                                ->get($url);
                    $json = $resp->json();
    
                    if (!isset($json['metaData']['code']) || (int)$json['metaData']['code'] !== 200) {
                        $skipped++;
                        $msg = $json['metaData']['message'] ?? 'API bukan 200';
                        $line = $logLine("SKIP reg={$reg} nama={$nama} nosep={$nosep} msg={$msg}");
                        $send(['type'=>'row','i'=>$i,'total'=>$total,'status'=>'skip','line'=>$line,'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped]);
                        continue;
                    }
    
                    $kelasHak = (int)($json['response']['klsRawat']['klsRawatHak'] ?? 0);
                    if ($kelasHak <= 0) {
                        $skipped++;
                        $line = $logLine("SKIP reg={$reg} nama={$nama} nosep={$nosep} msg=kelasHak_kosong");
                        $send(['type'=>'row','i'=>$i,'total'=>$total,'status'=>'skip','line'=>$line,'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped]);
                        continue;
                    }
    
                    if ($kelasLama === $kelasHak) {
                        $same++;
                        $line = $logLine("SAME reg={$reg} nama={$nama} nosep={$nosep} kelas={$kelasHak}");
                        $send(['type'=>'row','i'=>$i,'total'=>$total,'status'=>'same','line'=>$line,'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped]);
                        continue;
                    }
    
                    $aff = DB::connection('sqlsrv')
                        ->table('PasienList')
                        ->where('RegNum', $reg)
                        ->limit(1)
                        ->update(['Plavon_kls' => $kelasHak]);
    
                    if ($aff > 0) {
                        $updated++;
                        $line = $logLine("UPDATED reg={$reg} nama={$nama} nosep={$nosep} {$kelasLama}=>{$kelasHak}");
                        $send(['type'=>'row','i'=>$i,'total'=>$total,'status'=>'updated','line'=>$line,'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped]);
                    } else {
                        $failed++;
                        $line = $logLine("FAIL reg={$reg} nama={$nama} nosep={$nosep} update_0rows {$kelasLama}=>{$kelasHak}");
                        $send(['type'=>'row','i'=>$i,'total'=>$total,'status'=>'fail','line'=>$line,'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped]);
                    }
    
                } catch (\Throwable $e) {
                    $failed++;
                    $line = $logLine("ERROR reg={$reg} nama={$nama} nosep={$nosep} err=" . $e->getMessage());
                    $send(['type'=>'row','i'=>$i,'total'=>$total,'status'=>'fail','line'=>$line,'updated'=>$updated,'same'=>$same,'failed'=>$failed,'skipped'=>$skipped]);
                }
    
                // throttle (ms) agar API 6000 aman
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
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}    