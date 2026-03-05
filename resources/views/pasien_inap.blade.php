<!DOCTYPE html>
<html>
<head>
<title>Daftar Pasien Rawat Inap</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
    background:#f4f6f9;
}

.table thead{
    background:#0d6efd;
    color:white;
}

.badge-kelas{
    font-size:14px;
}

.btn-update{
    font-size:12px;
}

.container-box{
    background:white;
    padding:25px;
    border-radius:10px;
    box-shadow:0px 3px 8px rgba(0,0,0,0.1);
}
</style>

</head>
<body>
    <div class="container mt-4">

        <div class="container-box">
        
        <h4 class="mb-3">Daftar Pasien Rawat Inap</h4>
        
        <table class="table table-bordered table-hover table-striped align-middle">
        
        <thead>
        <tr>
        <th>No</th>
        <th>RegNum</th>
        <th>Nama</th>
        <th>Kelas RS</th>
        <th>Kelas BPJS</th>
        <th>No SEP</th>
        <th>Aksi</th>
        </tr>
        </thead>
        
        <tbody>
        
        @foreach($pasien as $p)
        
        <tr>
        
        <td>{{$loop->iteration}}</td>
        
        <td>{{$p->RegNum}}</td>
        
        <td>{{$p->Nama}}</td>
        
        <td>
        <span class="badge bg-info badge-kelas">
        {{$p->Kelas}}
        </span>
        </td>
        
        <td>
        
        @if($p->Plavon_kls == 1)
        <span class="badge bg-success">Kelas 1</span>
        
        @elseif($p->Plavon_kls == 2)
        <span class="badge bg-warning text-dark">Kelas 2</span>
        
        @elseif($p->Plavon_kls == 3)
        <span class="badge bg-danger">Kelas 3</span>
        
        @else
        <span class="badge bg-secondary">-</span>
        @endif
        
        </td>
        
        <td>{{$p->NoSEP}}</td>
        
        <td>
        
        <a href="/updatekelas/{{$p->NoSEP}}" 
        class="btn btn-primary btn-sm btn-update">
        
        Update
        
        </a>
        
        </td>
        
        </tr>
        
        @endforeach
        
        </tbody>
        
        </table>
        
        </div>
        </div>

    </body>
    </html>