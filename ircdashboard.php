<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>IRC DCC Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>

  <link id="themeStylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous">
</head>
<body class="bg-light">
 
<div class="container py-5">
  <header class="mb-4 text-center">
    <h1 class="display-5 fw-bold">
      <i class="fa-solid fa-terminal me-2"></i>IRC DCC Dashboard 
    </h1>
      
<a class="btn btn-primary" href="/irc_search.html" target="_blank">
  <i class="fa fa-terminal me-1"></i>OPEN THE SEARCH
</a>
  </header>

  <div class="row g-4">
    <!-- Status -->
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
          <h5 class="mb-0"><i class="fa-solid fa-signal me-2"></i>Status</h5>
        </div>
        <div class="card-body">
          <p><strong>Connected:</strong> <span id="connected">Loading...</span></p>
          <p><strong>Server:</strong> <span id="server">Loading...</span></p>
          <p><strong>Channel:</strong> <span id="channel">Loading...</span></p>
        </div>
      </div>
    </div>

    <!-- Request File -->
    <div class="col-md-6">
      <div class="card shadow-sm">
        <div class="card-header bg-success text-white">
          <h5 class="mb-0"><i class="fa-solid fa-download me-2"></i>Request File</h5>
        </div>
        <div class="card-body">
          <div class="input-group mb-3">
            <input type="text" id="fileCommand" class="form-control" placeholder="!filename" />
            <button class="btn btn-success" onclick="requestFile()">
              <i class="fa-solid fa-paper-plane"></i> Send
            </button>
          </div>
          <p id="fileRequestStatus" class="text-muted small mb-0"></p>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mt-3">
    <!-- Downloaded Files -->
    <div class="col-md-12">
      <div class="card shadow-sm">
        <div class="card-header bg-info text-white">
          <h5 class="mb-0"><i class="fa-solid fa-folder-open me-2"></i>Downloaded Files</h5>
        </div>
        <div class="card-body">
          <ul id="filesListTop10" class="list-group list-group-flush">
            <li class="list-group-item">Loading...</li>
          </ul>

          <div class="collapse" id="filesListMoreWrapper">
            <ul id="filesListMore" class="list-group list-group-flush"></ul>
          </div>

          <div class="d-grid mt-3">
            <button id="toggleMoreBtn" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#filesListMoreWrapper" aria-expanded="false">
              <i class="fa-solid fa-chevron-down me-2"></i>Show More
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 mt-3">
    <!-- Logs -->
    <div class="col-md-12">
      <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">
          <h5 class="mb-0"><i class="fa-solid fa-file-lines me-2"></i>Log Viewer (Last 50 Lines)</h5>
        </div>
        <div class="card-body" style="max-height: 300px; overflow-y: auto;">
          <pre id="logContent" class="mb-0 text-body small">Loading...</pre>
        </div>
      </div>
    </div>
  </div>
</div>


  <script>
    const API_BASE = "https://node2.nilla.local";

    async function loadStatus() {
      try {
        const res = await fetch(`${API_BASE}/status`);
        const data = await res.json();
        document.getElementById('connected').textContent = data.connected ? 'Yes' : 'No';
        document.getElementById('server').textContent = data.server;
        document.getElementById('channel').textContent = data.channel;
      } catch (e) {
        console.error("Error loading status:", e);
      }
    }

 async function loadFiles() {
  try {
    const res = await fetch(`${API_BASE}/downloaded-files`);
    let files = await res.json();

    // Sort descending by modified date
    files.sort((a, b) => new Date(b.modified) - new Date(a.modified));

    const top10 = files.slice(0, 10);
    const rest = files.slice(10);

    const topList = document.getElementById('filesListTop10');
    const moreList = document.getElementById('filesListMore');
    const toggleBtn = document.getElementById('toggleMoreBtn');
    const collapseWrapper = document.getElementById('filesListMoreWrapper');

    // Clear previous contents
    topList.innerHTML = '';
    moreList.innerHTML = '';

    // Add top 10 files
    if (top10.length === 0) {
      topList.innerHTML = "<li class='list-group-item'>No files downloaded yet.</li>";
    } else {
      top10.forEach(file => {
        const li = createFileListItem(file);
        topList.appendChild(li);
      });
    }

    // Add remaining files
    if (rest.length === 0) {
      collapseWrapper.classList.remove('show'); // keep hidden
      toggleBtn.style.display = 'none'; // hide button
    } else {
      rest.forEach(file => {
        const li = createFileListItem(file);
        moreList.appendChild(li);
      });
      toggleBtn.style.display = 'block'; // show button if hidden
    }
  } catch (e) {
    console.error("Error loading files:", e);
  }
}

// Helper function to create a file <li>
function createFileListItem(file) {
  const li = document.createElement('li');
  li.className = 'list-group-item';

  const a = document.createElement('a');
  a.href = `https://nilla.local/downloads/${encodeURIComponent(file.name)}`;
  a.textContent = `${file.name} (${file.size} bytes, modified: ${file.modified})`;
  a.target = "_blank";

  li.appendChild(a);
  return li;
}

    async function loadLogs() {
      try {
        const res = await fetch(`${API_BASE}/logs`);
        const logs = await res.json();
        document.getElementById('logContent').textContent = logs.reverse().join("");
      } catch (e) {
        document.getElementById('logContent').textContent = "Error loading log file.";
        console.error("Error loading logs:", e);
      }
    }

    async function requestFile() {
      const cmd = document.getElementById('fileCommand').value.trim();
      if (!cmd) {
        document.getElementById('fileRequestStatus').textContent = "Please enter a command.";
        return;
      }
      try {
        const res = await fetch(`${API_BASE}/request-file?cmd=${encodeURIComponent(cmd)}`);
        const data = await res.json();
        document.getElementById('fileRequestStatus').textContent = data.status || data.error;
      } catch (e) {
        document.getElementById('fileRequestStatus').textContent = "Error sending request.";
        console.error("Error sending request:", e);
      }
    }

    function refreshData() {
      loadStatus();
      loadFiles();
      loadLogs();
    }

    refreshData();
    setInterval(refreshData, 5000);
  </script>
  
  
  <script>
  const toggleBtn = document.getElementById('toggleMoreBtn');
  const collapseWrapper = document.getElementById('filesListMoreWrapper');

  collapseWrapper.addEventListener('shown.bs.collapse', () => {
    toggleBtn.innerHTML = '<i class="fa-solid fa-chevron-up me-2"></i>Show Less';
  });

  collapseWrapper.addEventListener('hidden.bs.collapse', () => {
    toggleBtn.innerHTML = '<i class="fa-solid fa-chevron-down me-2"></i>Show More';
  });
</script>


</body>
</html>
