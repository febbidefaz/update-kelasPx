<!DOCTYPE html>
<html>
<head>
  <title>Auto Sync Kelas BPJS Rawat Inap</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:#f4f6f9;}
    .card{border-radius:14px; box-shadow:0 4px 12px rgba(0,0,0,.08);}
    .log{height:420px; overflow:auto; font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12px; background:#0b1020; color:#d1d5db; padding:12px; border-radius:12px;}
  </style>
</head>
<body class="p-4">
<div class="container">
  <div class="card p-4">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h4 class="mb-1">Auto Sync Kelas BPJS – Rawat Inap</h4>
        <div class="text-muted">Link ini langsung running saat dibuka.</div>
        <div class="text-muted">Log file: <b id="logFile">-</b></div>
      </div>
      <div class="text-end">
        <div class="badge bg-dark">Run ID: <span id="runId">-</span></div>
      </div>
    </div>

    <hr>

    <div class="row g-3 mb-2">
      <div class="col-md-8">
        <div class="progress" style="height:22px;">
          <div id="bar" class="progress-bar" style="width:0%">0%</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="p-2 bg-light rounded">
          <div>Pasien diproses : <b><span id="total">0</span></b></div>
          <div>Berhasil update : <b><span id="updated">0</span></b></div>
          <div>Tidak berubah : <b><span id="same">0</span></b></div>
          <div>Error : <b><span id="failed">0</span></b></div>
        </div>
      </div>
    </div>

    <div class="log" id="log"></div>
  </div>
</div>

<script>
  const token = @json($token);
  const runUrl = @json(url("sync-kelas/run/$token"));  // otomatis ikut subfolder
  let es = null;
  let retryCount = 0;

  function addLog(line){
    try {
      const el = document.getElementById('log');
      el.innerHTML += line.replace(/</g,'&lt;') + "<br>";
      el.scrollTop = el.scrollHeight;
      console.debug('LOG:', line);
    } catch(e) {
      console.error('addLog error', e);
    }
  }

  function setBar(i, total){
    try {
      const pct = total ? Math.floor((i/total)*100) : 0;
      const bar = document.getElementById('bar');
      bar.style.width = pct + "%";
      bar.textContent = pct + "%";
    } catch(e) {
      console.error('setBar error', e);
    }
  }

  function start(){
    if(es) {
      try { es.close(); } catch(e){/*ignore*/ }
      es = null;
    }

    console.info('Connecting SSE ->', runUrl);
    addLog(`Mencoba koneksi ke ${runUrl} ...`);

    es = new EventSource(runUrl);

    es.onopen = function(){
      console.info('SSE open');
      addLog("Koneksi SSE terbuka.");
      retryCount = 0;
    };

    es.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        console.debug('SSE message:', data);

        if(data.type === 'start'){
          document.getElementById('runId').textContent = data.runId ?? '-';
          document.getElementById('logFile').textContent = data.logFile ?? '-';
          document.getElementById('total').textContent = data.total ?? 0;
          addLog(`Mulai… total pasien: ${data.total}`);
          return;
        }

        if(data.type === 'row'){
          // Pastikan fields ada
          const i = data.i ?? 0;
          const total = data.total ?? (parseInt(document.getElementById('total').textContent) || 0);

          setBar(i, total);

          // update counters realtime (server kirim angka terbaru)
          if (typeof data.updated !== 'undefined') document.getElementById('updated').textContent = data.updated;
          if (typeof data.same !== 'undefined')    document.getElementById('same').textContent    = data.same;
          if (typeof data.failed !== 'undefined')  document.getElementById('failed').textContent  = data.failed;

          // line bisa berupa string; lindungi dari tag html
          addLog(data.line ?? JSON.stringify(data));
          return;
        }

        if(data.type === 'done'){
          setBar(data.total, data.total);
          document.getElementById('total').textContent   = data.total ?? document.getElementById('total').textContent;
          document.getElementById('updated').textContent = data.updated ?? document.getElementById('updated').textContent;
          document.getElementById('same').textContent    = data.same ?? document.getElementById('same').textContent;
          document.getElementById('failed').textContent  = data.failed ?? document.getElementById('failed').textContent;

          addLog(`Selesai ✅ Updated=${data.updated}, Same=${data.same}, Error=${data.failed}, Skipped=${data.skipped}`);
          try { es.close(); } catch(e){/*ignore*/}
          es = null;
        }

      } catch (e) {
        console.error('SSE parse error:', e, event.data);
        addLog("Terima data bermasalah (lihat console).");
      }
    };

    es.onerror = (err) => {
      console.warn('SSE error', err);
      addLog("Koneksi SSE terputus. Mencoba reconnect...");
      try { es.close(); } catch(e){/*ignore*/}
      es = null;

      // reconnect dengan backoff singkat
      retryCount++;
      const wait = Math.min(10000, 1000 + (retryCount * 2000)); // up to 10s
      setTimeout(start, wait);
    };
  }

  // start otomatis saat page dibuka
  start();
</script>

</body>
</html>