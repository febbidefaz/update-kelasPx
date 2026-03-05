<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncKelasBpjsInap extends Command
{
    protected $signature = 'bpjs:sync-kelas-inap {--limit=300} {--sleep=200}';
    protected $description = 'Sinkronisasi kelas BPJS (Plavon_kls) pasien rawat inap berdasarkan NoSEP via API findsep';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');   // batasi jumlah pasien per run
        $sleep = (int) $this->option('sleep');   // delay antar request (ms)

        // Karena DaftarPasienRawatInap1_sp adalah VIEW
        $rows = DB::connection('sqlsrv')->select("
            SELECT TOP ($limit) RegNum, NoSEP, Nama, Plavon_kls
            FROM dbo.DaftarPasienRawatInapSEP
            ORDER BY ID DESC
        ");

        $total = count($rows);
        $this->info("Total kandidat: {$total}");

        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($rows as $r) {
            $nosep = trim((string) $r->NoSEP);
            $reg   = trim((string) $r->RegNum);

            try {
                $apiUrl = "http://192.168.1.200:6000/api/findsep?nosep=" . urlencode($nosep);

                $resp = Http::timeout(8)->get($apiUrl);

                $json = $resp->json();

                if (!isset($json['metaData']['code']) || (int)$json['metaData']['code'] !== 200) {
                    $skipped++;
                    Log::warning("SYNC_KELAS: skip nosep={$nosep} reg={$reg} meta=".json_encode($json['metaData'] ?? null));
                    usleep($sleep * 1000);
                    continue;
                }

                $kelasHak = $json['response']['klsRawat']['klsRawatHak'] ?? null;
                if ($kelasHak === null) {
                    $skipped++;
                    Log::warning("SYNC_KELAS: kelasHak null nosep={$nosep} reg={$reg}");
                    usleep($sleep * 1000);
                    continue;
                }

                $kelasHak = (int)$kelasHak;
                $kelasLama = (int)($r->Plavon_kls ?? 0);

                // Kalau sudah sama, skip
                if ($kelasLama === $kelasHak) {
                    $skipped++;
                    usleep($sleep * 1000);
                    continue;
                }

                // Update Plavon_kls di PasienList berdasarkan RegNum (lebih direct, tidak perlu cari Therapy lagi)
                $aff = DB::connection('sqlsrv')
                    ->table('PasienList')
                    ->where('RegNum', $reg)
                    ->update(['Plavon_kls' => $kelasHak]);

                if ($aff > 0) {
                    $updated++;
                    Log::info("SYNC_KELAS: updated reg={$reg} nosep={$nosep} {$kelasLama} => {$kelasHak}");
                } else {
                    $failed++;
                    Log::warning("SYNC_KELAS: update 0 rows reg={$reg} nosep={$nosep} {$kelasLama} => {$kelasHak}");
                }

            } catch (\Throwable $e) {
                $failed++;
                Log::error("SYNC_KELAS: error nosep={$nosep} reg={$reg} msg=".$e->getMessage());
            }

            usleep($sleep * 1000); // throttle supaya API tidak “sesak”
        }

        $this->info("Selesai. Updated={$updated}, Skipped={$skipped}, Failed={$failed}");
        return self::SUCCESS;
    }
}