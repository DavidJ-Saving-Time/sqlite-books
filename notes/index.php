<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
</head>
<body class="vh-100 d-flex">
    <!-- Sidebar -->
    <div id="sidebar" class="border-end bg-light p-2 d-flex flex-column" style="width:250px; overflow-y:auto;">
        <button id="newNote" class="btn btn-sm btn-success w-100 mb-2">
            <i class="fa-solid fa-plus me-1"></i> New Note
        </button>
        <ul id="noteList" class="list-group list-group-flush flex-grow-1"></ul>
    </div>

    <!-- Main content -->
    <div class="flex-grow-1 d-flex flex-column">
        <!-- Top Bar -->
        <div id="topBar" class="border-bottom p-1 d-flex align-items-center">
            <div class="me-2 text-muted small">Open Notes:</div>
            <div id="openTabs" class="flex-grow-1 d-flex align-items-center"></div>
            <button id="saveBtn" class="btn btn-sm btn-success me-2">
                <i class="fa-solid fa-floppy-disk me-1"></i> Save
            </button>
            <button id="viewBtn" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-eye me-1"></i> View
            </button>
        </div>

        <!-- Editor / Viewer -->
        <div id="editorPane" class="flex-grow-1 position-relative">
            <textarea id="editor"></textarea>
            <div id="viewer" class="h-100 w-100 overflow-auto p-3 bg-white border rounded" style="display:none;"></div>
        </div>
    </div>

<script>
    tinymce.init({ selector:'#editor', height:'100%', menubar:false, branding:false });

    const notesCache = {};
    const openNotes = [];
    let activeId = null;
    let viewing = false;

    async function loadList(q='') {
        const url = q ? `api.php?q=${encodeURIComponent(q)}` : 'api.php';
        const res = await fetch(url);
        const data = await res.json();
        const list = document.getElementById('noteList');
        list.innerHTML = '';
        data.forEach(n => {
            const li = document.createElement('li');
            li.className = 'list-group-item list-group-item-action';
            li.textContent = n.title;
            li.onclick = () => openNote(n.id);
            list.appendChild(li);
        });
    }

    async function openNote(id) {
        if (!notesCache[id]) {
            const res = await fetch('api.php/' + id);
            if (!res.ok) return;
            notesCache[id] = await res.json();
        }
        if (!openNotes.includes(id)) {
            openNotes.push(id);
            renderTabs();
        }
        activeId = id;
        tinymce.get('editor').setContent(notesCache[id].text || '');
        localStorage.setItem('currentNote', id);
        highlightTabs();

        if (viewing) toggleView(); // auto switch back to edit mode
    }

    function renderTabs() {
        const tabs = document.getElementById('openTabs');
        tabs.innerHTML = '';
        openNotes.forEach(id => {
            const btn = document.createElement('button');
            btn.className = 'btn btn-sm btn-outline-secondary d-inline-flex align-items-center text-truncate me-1';
            btn.style.maxWidth = '150px';
            btn.onclick = () => openNote(id);
            btn.innerHTML = `
                <span class="me-1 flex-grow-1 text-truncate">${notesCache[id]?.title || ('Note ' + id)}</span>
                <i class="fa-solid fa-xmark ms-2"></i>`;
            btn.querySelector('i').onclick = (e) => { e.stopPropagation(); closeTab(id); };
            tabs.appendChild(btn);
        });
        highlightTabs();
    }

    function closeTab(id) {
        const idx = openNotes.indexOf(id);
        if (idx !== -1) {
            openNotes.splice(idx, 1);
            renderTabs();
            if (activeId === id) {
                activeId = openNotes[idx] || openNotes[idx - 1] || null;
                if (activeId) openNote(activeId);
                else {
                    tinymce.get('editor').setContent('');
                    localStorage.removeItem('currentNote');
                }
            }
        }
    }

    function highlightTabs() {
        const tabs = document.getElementById('openTabs').children;
        for (let i = 0; i < tabs.length; i++) {
            const id = openNotes[i];
            tabs[i].classList.toggle('btn-primary', id === activeId);
            tabs[i].classList.toggle('btn-outline-secondary', id !== activeId);
        }
    }

    async function saveNote() {
        if (!activeId) return;
        const content = tinymce.get('editor').getContent();
        const title = notesCache[activeId]?.title || 'Untitled';
        await fetch('api.php/' + activeId, {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify({title, text: content})
        });
        notesCache[activeId].text = content;
        loadList();
    }

    async function toggleView() {
        if (!activeId) return;
        const viewer = document.getElementById('viewer');
        const editor = tinymce.get('editor');
        const btn = document.getElementById('viewBtn');
        const sidebar = document.getElementById('sidebar');

        if (!viewing) {
            const res = await fetch('api.php/' + activeId);
            if (!res.ok) return;
            const data = await res.json();
            notesCache[activeId] = data;
            viewer.innerHTML = data.text || '';
            viewer.style.display = 'block';
            editor.getContainer().style.display = 'none';
            btn.innerHTML = `<i class="fa-solid fa-pen me-1"></i>Edit`;
            document.getElementById('saveBtn').disabled = true;
            sidebar.style.display = 'none'; // collapse sidebar
            viewing = true;
        } else {
            viewer.style.display = 'none';
            editor.getContainer().style.display = '';
            btn.innerHTML = `<i class="fa-solid fa-eye me-1"></i>View`;
            document.getElementById('saveBtn').disabled = false;
            sidebar.style.display = ''; // restore sidebar
            viewing = false;
        }
    }

    document.addEventListener('keydown', e => {
        if (e.ctrlKey && e.key === 's') { e.preventDefault(); saveNote(); }
        if (e.altKey && e.key >= '1' && e.key <= '9') {
            const idx = parseInt(e.key) - 1;
            if (openNotes[idx]) openNote(openNotes[idx]);
        }
    });

    document.getElementById('saveBtn').onclick = saveNote;
    document.getElementById('viewBtn').onclick = toggleView;

    document.getElementById('newNote').onclick = async () => {
        const title = prompt('Title for new note');
        const res = await fetch('api.php/0', { 
            method:'POST', 
            headers:{'Content-Type':'application/json'}, 
            body: JSON.stringify({title: title || 'Untitled', text: ''}) 
        });
        const data = await res.json();
        await loadList();
        openNote(data.id);
    };

    loadList();
    const last = localStorage.getItem('currentNote');
    if (last) openNote(parseInt(last));
</script>
</body>
</html>

