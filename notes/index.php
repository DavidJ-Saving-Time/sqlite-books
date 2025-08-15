<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Notes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
</head>
<body class="vh-100 d-flex">
    <div id="sidebar" class="border-end p-2" style="width:250px; overflow-y:auto;">
        <button id="newNote" class="btn btn-sm btn-success w-100 mb-2">New Note</button>
        <ul id="noteList" class="list-group"></ul>
    </div>
    <div class="flex-grow-1 d-flex flex-column">
        <div id="openTabs" class="border-bottom p-1"></div>
        <textarea id="editor"></textarea>
    </div>
<script>
    tinymce.init({ selector:'#editor', height:'100%', menubar:false, branding:false });

    const notesCache = {};
    const openNotes = [];
    let activeId = null;

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
    }

    function renderTabs() {
        const tabs = document.getElementById('openTabs');
        tabs.innerHTML = '';
        openNotes.forEach(id => {
            const btn = document.createElement('button');
            btn.className = 'btn btn-sm btn-outline-secondary me-1';
            btn.textContent = notesCache[id]?.title || ('Note ' + id);
            btn.onclick = () => openNote(id);
            tabs.appendChild(btn);
        });
        highlightTabs();
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

    document.addEventListener('keydown', e => {
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            saveNote();
        }
        if (e.altKey && e.key >= '1' && e.key <= '9') {
            const idx = parseInt(e.key) - 1;
            if (openNotes[idx]) openNote(openNotes[idx]);
        }
    });

    document.getElementById('newNote').onclick = async () => {
        const title = prompt('Title for new note');
        const res = await fetch('api.php/0', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({title: title || 'Untitled', text: ''}) });
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
