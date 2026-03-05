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
let es = null;

function addLog(line){
  const el = document.getElementById('log');
  el.innerHTML += line + "<br>";
  el.scrollTop = el.scrollHeight;
}

function setBar(i,total){
  const pct = total ? Math.floor((i/total)*100) : 0;
  const bar = document.getElementById('bar');
  bar.style.width = pct + "%";
  bar.textContent = pct + "%";
}

function start(){
  if(es) es.close();
  es = new EventSource(`/sync-kelas/run/${encodeURIComponent(token)}`);

  es.onmessage = (event) => {
    const data = JSON.parse(event.data);

    if(data.type === 'start'){
      document.getElementById('runId').textContent = data.runId;
      document.getElementById('logFile').textContent = data.logFile;
      document.getElementById('total').textContent = data.total;
      addLog(`Mulai… total pasien: ${data.total}`);
      return;
    }

    if(data.type === 'row'){
      setBar(data.i, data.total);

      // update counters realtime (server kirim angka terbaru)
      document.getElementById('updated').textContent = data.updated;
      document.getElementById('same').textContent    = data.same;
      document.getElementById('failed').textContent  = data.failed;

      addLog(data.line);
      return;
    }

    if(data.type === 'done'){
      setBar(data.total, data.total);
      document.getElementById('total').textContent   = data.total;
      document.getElementById('updated').textContent = data.updated;
      document.getElementById('same').textContent    = data.same;
      document.getElementById('failed').textContent  = data.failed;

      addLog(`Selesai ✅ Updated=${data.updated}, Same=${data.same}, Error=${data.failed}, Skipped=${data.skipped}`);
      es.close();
    }
  };

  es.onerror = () => {
    addLog("Koneksi SSE terputus.");
    if(es) es.close();
  };
}

start(); // auto-run saat page dibuka
</script>

</body>
</html>