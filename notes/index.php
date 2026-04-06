<?php
require_once '../db.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WordPro — Notes</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Crimson+Pro:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="/css/all.min.css" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js" referrerpolicy="origin"></script>
    <style>
        /* ============================================================
           WordPro — The Writer's Study
           Warm walnut sidebar · Amber accents · Cream editor
           Cormorant Garamond + Crimson Pro
           ============================================================ */
        :root {
            --walnut:         #18100a;
            --walnut-mid:     #22160e;
            --walnut-light:   #2e1f14;
            --walnut-border:  #5a4030;
            --amber:          #e0a84a;
            --amber-dim:      #a07030;
            --amber-glow:     rgba(224,168,74,.18);
            --cream:          #fdf7ec;
            --text-warm:      #f5ead8;
            --text-mid:       #d4b88a;
            --text-muted:     #a08060;
            --forest:         #3d7252;
            --forest-hover:   #2e5840;
            --danger-color:   #c03838;
            --topbar-bg:      #110c07;
            --font-display:   'Cormorant Garamond', Georgia, serif;
            --font-body:      'Crimson Pro', Georgia, serif;
        }

        /* ── Layout ─────────────────────────────────────────────── */
        body { background: var(--walnut) !important; }

        /* ── Sidebar ─────────────────────────────────────────────── */
        #sidebar {
            display: flex !important;
            flex-direction: column !important;
            width: 265px !important;
            flex-shrink: 0 !important;
            background: var(--walnut) !important;
            border-right: 1px solid var(--walnut-border) !important;
            background-image:
                repeating-linear-gradient(
                    135deg,
                    transparent 0, transparent 3px,
                    rgba(255,255,255,.007) 3px, rgba(255,255,255,.007) 6px
                ) !important;
        }
        #sidebarTop {
            padding: 12px 10px 10px;
            border-bottom: 1px solid var(--walnut-border);
            flex-shrink: 0;
        }
        #sidebarContent {
            overflow-y: auto; flex: 1; padding: 6px 0;
            scrollbar-width: thin;
            scrollbar-color: var(--walnut-border) transparent;
        }
        #sidebarContent::-webkit-scrollbar { width: 3px; }
        #sidebarContent::-webkit-scrollbar-thumb { background: var(--walnut-border); border-radius: 2px; }

        /* ── Sidebar buttons ────────────────────────────────────── */
        .sidebar-btns { display: flex; gap: 6px; margin-bottom: 10px; }

        #newNote {
            flex-grow: 1;
            background: var(--forest) !important;
            border: none !important;
            border-radius: 6px !important;
            color: #d0ead8 !important;
            font-family: var(--font-body);
            font-size: 1rem;
            letter-spacing: .01em;
            padding: 6px 10px !important;
            transition: background .15s;
        }
        #newNote:hover { background: var(--forest-hover) !important; }

        #newFolder {
            background: transparent !important;
            border: 1px solid var(--walnut-border) !important;
            border-radius: 6px !important;
            color: var(--amber) !important;
            width: 36px; padding: 0 !important;
            transition: border-color .15s, background .15s;
        }
        #newFolder:hover { background: var(--amber-glow) !important; border-color: var(--amber-dim) !important; }

        /* ── Search ─────────────────────────────────────────────── */
        #searchInput {
            background: var(--walnut-mid) !important;
            border: 1px solid var(--walnut-border) !important;
            border-radius: 6px !important;
            color: var(--text-warm) !important;
            font-family: var(--font-body);
            font-size: 1rem;
            padding: 5px 10px !important;
            transition: border-color .15s, box-shadow .15s;
        }
        #searchInput::placeholder { color: var(--text-muted) !important; }
        #searchInput:focus {
            background: var(--walnut-light) !important;
            border-color: var(--amber-dim) !important;
            box-shadow: 0 0 0 2px var(--amber-glow) !important;
            outline: none;
        }

        /* ── Folder sections ────────────────────────────────────── */
        .folder-section { margin: 0 6px 1px; border-radius: 5px; }
        .folder-header {
            display: flex; align-items: center; padding: 5px 8px; gap: 5px;
            cursor: pointer; border-radius: 5px; user-select: none;
            transition: background .15s;
        }
        .folder-header:hover { background: rgba(255,255,255,.08); }
        .folder-toggle { color: var(--amber-dim); font-size: .6rem; width: 10px; flex-shrink: 0; }
        .folder-name {
            flex: 1;
            font-family: var(--font-display);
            font-size: .97rem; font-weight: 600;
            letter-spacing: .06em; text-transform: uppercase;
            color: var(--text-warm);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .folder-count { font-size: .78rem; color: var(--text-muted); font-variant-numeric: tabular-nums; flex-shrink: 0; }
        .folder-actions { display: none; gap: 2px; align-items: center; }
        .folder-header:hover .folder-actions { display: flex; }
        .folder-btn {
            background: none; border: none; padding: 2px 4px; cursor: pointer;
            color: var(--text-muted); font-size: .72rem; border-radius: 3px;
            transition: color .12s, background .12s;
        }
        .folder-btn:hover { color: var(--amber); background: var(--amber-glow); }

        /* ── Note items ─────────────────────────────────────────── */
        .note-list { list-style: none; padding: 2px 0 4px 18px; margin: 0; }
        .note-item {
            display: flex; align-items: center; padding: 5px 8px 5px 6px; gap: 5px;
            cursor: pointer; border-radius: 4px;
            font-family: var(--font-body);
            font-size: 1rem; color: var(--text-mid);
            border-left: 2px solid transparent;
            transition: color .15s, background .15s, border-left-color .15s;
        }
        .note-item:hover { color: var(--text-warm); background: rgba(255,255,255,.07); border-left-color: var(--amber-dim); }
        .note-item.active { color: var(--amber) !important; background: rgba(224,168,74,.2) !important; border-left-color: var(--amber) !important; }
        .note-item.active .drag-handle { visibility: hidden; }
        .note-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .note-snippet { font-size: .82rem; color: var(--text-mid); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .note-snippet mark { background: rgba(200,144,64,.22); color: var(--amber); border-radius: 2px; padding: 0 1px; }

        /* ── Drag handles ───────────────────────────────────────── */
        .drag-handle {
            cursor: grab; color: var(--walnut-border); font-size: .9rem;
            flex-shrink: 0; line-height: 1; visibility: hidden; transition: color .12s;
        }
        .drag-handle:hover { color: var(--amber-dim); }
        .folder-header:hover .drag-handle,
        .note-item:hover .drag-handle { visibility: visible; }

        /* ── Drag indicators ────────────────────────────────────── */
        .note-item.drag-over-top    { border-top: 2px solid var(--amber); }
        .note-item.drag-over-bottom { border-bottom: 2px solid var(--amber); }
        .folder-section.drag-over-top    { border-top: 2px solid var(--amber); }
        .folder-section.drag-over-bottom { border-bottom: 2px solid var(--amber); }
        .folder-header.drag-over-into { background: var(--amber-glow) !important; }
        .folder-section.dragging, .note-item.dragging { opacity: .3; }
        .folder-section.collapsed .note-list { display: none; }

        /* ── Top bar ────────────────────────────────────────────── */
        #topBar {
            background: var(--topbar-bg) !important;
            border-bottom: 1px solid var(--walnut-border) !important;
            min-height: 40px; padding: 0 8px !important;
            gap: 6px; flex-shrink: 0;
        }
        #topBar .text-muted {
            color: var(--text-mid) !important;
            font-family: var(--font-display);
            font-size: .7rem !important;
            letter-spacing: .1em; text-transform: uppercase; white-space: nowrap;
        }
        #openTabs { overflow-x: auto; overflow-y: hidden; flex: 1; gap: 2px; scrollbar-width: none; }
        #openTabs::-webkit-scrollbar { display: none; }

        /* Open note tab chips */
        #openTabs .btn {
            font-family: var(--font-body) !important;
            font-size: .95rem !important;
            border-radius: 4px 4px 0 0 !important;
            border: 1px solid var(--walnut-border) !important;
            border-bottom: none !important;
            background: var(--walnut-mid) !important;
            color: var(--text-muted) !important;
            padding: 3px 10px !important;
            height: 30px; align-self: flex-end;
            transition: background .12s, color .12s;
        }
        #openTabs .btn:hover { background: var(--walnut-light) !important; color: var(--text-warm) !important; }
        #openTabs .btn.btn-primary,
        #openTabs .btn-primary {
            background: var(--walnut-light) !important;
            color: var(--amber) !important;
            border-color: var(--amber-dim) !important;
            border-top: 2px solid var(--amber) !important;
        }

        /* ── Top bar action buttons ─────────────────────────────── */
        #saveBtn {
            background: var(--forest) !important; border: none !important;
            border-radius: 5px !important; color: #d0ead8 !important;
            font-family: var(--font-body); font-size: .95rem;
            padding: 4px 14px !important; height: 28px;
            transition: background .15s; white-space: nowrap;
        }
        #saveBtn:hover:not(:disabled) { background: var(--forest-hover) !important; }
        #saveBtn:disabled { opacity: .35 !important; }

        #viewBtn {
            background: transparent !important;
            border: 1px solid var(--walnut-border) !important;
            border-radius: 5px !important; color: var(--text-mid) !important;
            font-family: var(--font-body); font-size: .95rem;
            padding: 4px 12px !important; height: 28px;
            transition: border-color .15s, color .15s; white-space: nowrap;
        }
        #viewBtn:hover { border-color: var(--amber-dim) !important; color: var(--amber) !important; }

        #exportBtn {
            background: transparent !important;
            border: 1px solid var(--walnut-border) !important;
            border-radius: 5px !important; color: var(--text-mid) !important;
            font-family: var(--font-body); font-size: .95rem;
            padding: 4px 12px !important; height: 28px;
            transition: all .15s; white-space: nowrap;
        }
        #exportBtn:hover { border-color: var(--amber-dim) !important; color: var(--amber) !important; }
        #exportBtn:disabled { opacity: .4 !important; }

        #deleteBtn {
            background: transparent !important;
            border: 1px solid var(--walnut-border) !important;
            border-radius: 5px !important; color: var(--text-muted) !important;
            font-family: var(--font-body); font-size: .95rem;
            padding: 4px 10px !important; height: 28px;
            transition: all .15s;
        }
        #deleteBtn:hover { background: rgba(168,50,50,.15) !important; border-color: var(--danger-color) !important; color: #e07070 !important; }

        /* ── Editor / Viewer ────────────────────────────────────── */
        #editorPane { background: var(--cream); }
        #viewer {
            background: var(--cream) !important;
            border: none !important; border-radius: 0 !important;
            font-family: var(--font-body);
            font-size: 1.1rem; line-height: 1.8; color: #2a1a0c;
            max-width: 720px; margin: 0 auto;
            padding: 44px 52px !important;
        }
        .tox-tinymce { border: none !important; }

        /* ── Modals (shared theme) ──────────────────────────────── */
        #newNoteModal .modal-content,
        #confirmModal .modal-content,
        #promptModal  .modal-content,
        #alertModal   .modal-content {
            background: var(--walnut-mid);
            border: 1px solid var(--walnut-border);
            border-radius: 10px; color: var(--text-warm);
            font-family: var(--font-body);
            box-shadow: 0 24px 60px rgba(0,0,0,.7);
        }
        #newNoteModal .modal-header,
        #confirmModal .modal-header,
        #promptModal  .modal-header,
        #alertModal   .modal-header { border-bottom: 1px solid var(--walnut-border); padding: 14px 18px; }
        #newNoteModal .modal-title,
        #confirmModal .modal-title,
        #promptModal  .modal-title,
        #alertModal   .modal-title {
            font-family: var(--font-display); font-size: 1.15rem;
            letter-spacing: .03em; color: var(--text-warm);
        }
        #newNoteModal .btn-close,
        #confirmModal .btn-close,
        #promptModal  .btn-close,
        #alertModal   .btn-close { filter: invert(1) opacity(.45); }
        #newNoteModal .btn-close:hover,
        #confirmModal .btn-close:hover,
        #promptModal  .btn-close:hover,
        #alertModal   .btn-close:hover { filter: invert(1) opacity(.8); }
        #newNoteModal .modal-body,
        #confirmModal .modal-body,
        #promptModal  .modal-body,
        #alertModal   .modal-body { padding: 20px 18px 16px; color: var(--text-warm); font-family: var(--font-body); font-size: 1rem; }
        #newNoteModal .modal-footer,
        #confirmModal .modal-footer,
        #promptModal  .modal-footer,
        #alertModal   .modal-footer { border-top: 1px solid var(--walnut-border); padding: 12px 18px; }
        #newNoteModal .form-label {
            font-family: var(--font-display); font-size: .75rem;
            letter-spacing: .1em; text-transform: uppercase;
            color: var(--text-muted); margin-bottom: 6px; display: block;
        }
        #newNoteModal .form-control,
        #newNoteModal .form-select {
            background: var(--walnut) !important;
            border: 1px solid var(--walnut-border) !important;
            border-radius: 6px !important;
            color: var(--text-warm) !important;
            font-family: var(--font-body); font-size: .95rem;
        }
        #newNoteModal .form-control:focus,
        #newNoteModal .form-select:focus {
            border-color: var(--amber-dim) !important;
            box-shadow: 0 0 0 2px var(--amber-glow) !important;
        }
        #newNoteModal .form-control::placeholder { color: var(--text-muted) !important; }
        #newNoteModal .form-select option { background: var(--walnut-mid); }
        #newNoteModal .btn-secondary {
            background: transparent !important; border: 1px solid var(--walnut-border) !important;
            color: var(--text-muted) !important; font-family: var(--font-body); border-radius: 6px !important;
        }
        #newNoteModal .btn-secondary:hover { border-color: var(--text-mid) !important; color: var(--text-warm) !important; }
        #newNoteModal .btn-success,
        #newNoteModal #newFolderInlineConfirm {
            background: var(--forest) !important; border: none !important;
            color: #d0ead8 !important; font-family: var(--font-body);
            border-radius: 6px !important; transition: background .15s;
        }
        #newNoteModal .btn-success:hover,
        #newNoteModal #newFolderInlineConfirm:hover { background: var(--forest-hover) !important; }
        #newFolderInlineBtn {
            background: transparent !important; border: 1px solid var(--walnut-border) !important;
            color: var(--amber) !important; border-radius: 6px !important; transition: all .15s;
        }
        #newFolderInlineBtn:hover { background: var(--amber-glow) !important; border-color: var(--amber-dim) !important; }
        #newNoteModal .input-group .form-control { border-radius: 6px 0 0 6px !important; }
        #newNoteModal #newFolderInlineConfirm { border-radius: 0 !important; }
        #newNoteModal #newFolderInlineCancel {
            background: transparent !important; border: 1px solid var(--walnut-border) !important;
            border-left: none !important; color: var(--text-muted) !important;
            border-radius: 0 6px 6px 0 !important;
        }
        .modal-backdrop { background: rgba(0,0,0,.75) !important; }
    </style>
</head>
<body style="margin:0;padding:0;height:100vh;display:flex;flex-direction:column;overflow:hidden;">
<?php include '../research/navbar.php'; ?>
<div style="flex:1;display:flex;overflow:hidden;padding-top:56px;">

    <!-- Sidebar -->
    <div id="sidebar">
        <div id="sidebarTop">
            <div class="sidebar-btns">
                <button id="newNote" class="btn btn-sm btn-success flex-grow-1">
                    <i class="fa-solid fa-plus me-1"></i> New Note
                </button>
                <button id="newFolder" class="btn btn-sm btn-outline-secondary" title="New Folder">
                    <i class="fa-solid fa-folder-plus"></i>
                </button>
            </div>
            <input id="searchInput" type="search" class="form-control form-control-sm" placeholder="Search notes…" autocomplete="off">
        </div>
        <div id="sidebarContent"></div>
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
            <button id="viewBtn" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fa-solid fa-eye me-1"></i> View
            </button>
            <button id="exportBtn" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fa-solid fa-file-word me-1"></i> Export
            </button>
            <button id="deleteBtn" class="btn btn-sm btn-danger">
                <i class="fa-solid fa-trash me-1"></i> Delete
            </button>
        </div>

        <!-- Editor / Viewer -->
        <div id="editorPane" class="flex-grow-1 position-relative">
            <textarea id="editor"></textarea>
            <div id="viewer" class="h-100 w-100 overflow-auto p-3 bg-white border rounded" style="display:none;"></div>
        </div>
    </div>

<script>
tinymce.init({
    selector: '#editor',
    height: '100%',
    menubar: false,
    branding: false,
    resize: false,
    plugins: 'lists advlist table wordcount searchreplace pagebreak',
    toolbar: 'undo redo | fontfamily fontsize | styles | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist | outdent indent | table | pagebreak | searchreplace',
    toolbar_sticky: true,
    font_family_formats:
        'Crimson Pro=Crimson Pro,Georgia,serif;' +
        'Cormorant Garamond=Cormorant Garamond,Georgia,serif;' +
        'EB Garamond=EB Garamond,Georgia,serif;' +
        'Lora=Lora,Georgia,serif;' +
        'Merriweather=Merriweather,Georgia,serif;' +
        'Georgia=Georgia,serif;' +
        'Arial=Arial,Helvetica,sans-serif;' +
        'Verdana=Verdana,Geneva,sans-serif;' +
        'Courier New=Courier New,Courier,monospace',
    font_size_formats: '10pt 11pt 12pt 13pt 14pt 16pt 18pt 20pt 24pt 28pt 32pt',
    setup: function(editor) {
        editor.on('input Change', function() {
            isDirty = true;
            // Immediately back up to localStorage as crash protection
            if (activeId) {
                try { localStorage.setItem('wp_draft_' + activeId, editor.getContent()); } catch(e) {}
            }
            // Debounced DB save: fires 3s after the user stops typing
            clearTimeout(window._saveDebounce);
            window._saveDebounce = setTimeout(() => { if (isDirty) bgSave(); }, 3000);
        });
    },
    content_style: `
        @import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Crimson+Pro:ital,wght@0,400;0,600;1,400&family=EB+Garamond:ital,wght@0,400;0,600;1,400&family=Lora:ital,wght@0,400;0,600;1,400&family=Merriweather:ital,wght@0,300;0,400;1,300;1,400&display=swap');
        html {
            background: #cbc5bc;
            min-height: 100%;
        }
        body {
            background: #fffdf8;
            margin: 36px auto 72px;
            max-width: 760px;
            min-height: 1020px;
            padding: 82px 92px;
            box-shadow: 0 1px 4px rgba(0,0,0,.14), 0 6px 24px rgba(0,0,0,.2), 0 12px 48px rgba(0,0,0,.08);
            font-family: 'Crimson Pro', Georgia, serif;
            font-size: 13pt;
            line-height: 1.78;
            color: #1e1408;
            border-radius: 1px;
            /* A4 page guide lines: repeat every 1122px (A4 height at 96dpi) */
            background-image: repeating-linear-gradient(
                to bottom,
                transparent 0,
                transparent calc(1122px - 2px),
                rgba(180,155,120,.55) calc(1122px - 1px),
                rgba(180,155,120,.55) 1122px,
                transparent calc(1122px + 1px)
            );
        }
        p { margin: 0; }
        h1, h2, h3, h4 {
            font-family: 'Cormorant Garamond', Georgia, serif;
            color: #1a1008; margin: 1.2em 0 0.4em; font-weight: 600;
        }
        h1 { font-size: 2.2em; border-bottom: 1px solid #e0d8cc; padding-bottom: .25em; }
        h2 { font-size: 1.65em; }
        h3 { font-size: 1.35em; }
        h4 { font-size: 1.1em; }
        blockquote {
            border-left: 3px solid #c89040; margin: 1.2em 0;
            padding: .4em 1.2em; color: #5a4530; font-style: italic;
        }
        a { color: #7a5828; text-decoration: underline; }
        pre { background: #f5f0e8; border: 1px solid #e0d8cc; border-radius: 4px; padding: 12px 16px; font-size: 10pt; }
        table { border-collapse: collapse; width: 100%; margin: 1em 0; }
        td, th { border: 1px solid #ddd; padding: 7px 12px; }
        th { background: #f5f0e8; font-family: 'Cormorant Garamond', Georgia, serif; font-weight: 600; }

        /* Page break — bleeds to page edges, shows desk colour as the gap */
        img.mce-pagebreak {
            display: block !important;
            width: calc(100% + 184px) !important;
            height: 56px !important;
            margin: 8px -92px !important;
            border-top: 1px solid rgba(0,0,0,.18) !important;
            border-bottom: 1px solid rgba(0,0,0,.18) !important;
            border-left: none !important;
            border-right: none !important;
            background: #cbc5bc !important;
            box-shadow:
                inset 0 8px 14px -6px rgba(0,0,0,.22),
                inset 0 -8px 14px -6px rgba(0,0,0,.22) !important;
            cursor: default !important;
            position: relative !important;
        }
        @media print {
            img.mce-pagebreak { display: none !important; }
            .mce-pagebreak    { page-break-after: always !important; }
        }
    `,
    style_formats: [
        { title: 'Paragraph', format: 'p' },
        { title: 'Heading 1', format: 'h1' },
        { title: 'Heading 2', format: 'h2' },
        { title: 'Heading 3', format: 'h3' },
        { title: 'Heading 4', format: 'h4' },
        { title: 'Blockquote', format: 'blockquote' },
        { title: 'Code block', format: 'pre' },
    ],
});

// --- Core state ---
const notesCache = {};
const openNotes  = [];
let activeId = null;
let viewing  = false;

// --- Organisation state ---
let folders = [];
let notes   = [];
let collapsedFolders = new Set(JSON.parse(localStorage.getItem('collapsedFolders') || '[]'));

// --- Drag state ---
let dragType = null; // 'note' | 'folder'
let dragId   = null;
let reorderTimer = null;

function escHtml(s) {
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// =====================================================================
// Sidebar loading & rendering
// =====================================================================

async function loadSidebar(q = '') {
    const [noteRes, folderRes] = await Promise.all([
        fetch(q ? `api.php?q=${encodeURIComponent(q)}` : 'api.php'),
        q ? Promise.resolve(null) : fetch('api.php/folders')
    ]);
    notes = await noteRes.json();
    if (!q) {
        folders = (await folderRes.json()).map(f => ({
            id: +f.id, name: f.name, sort_order: +f.sort_order
        }));
    }
    renderSidebar(q);
}

function renderSidebar(q = '') {
    const container = document.getElementById('sidebarContent');
    container.innerHTML = '';

    // Normalise folder_id to number|null
    notes.forEach(n => { n.folder_id = n.folder_id ? +n.folder_id : null; });

    if (q) {
        notes.forEach(n => container.appendChild(makeNoteEl(n, false)));
        highlightList();
        return;
    }

    // Group by folder
    const grouped = {};
    const unfiled  = [];
    notes.forEach(n => {
        if (n.folder_id) { (grouped[n.folder_id] = grouped[n.folder_id] || []).push(n); }
        else { unfiled.push(n); }
    });

    folders.forEach((f, i) => renderFolderSection(container, f, grouped[f.id] || [], i));

    // Unfiled always last; only show if it has notes or there are no folders
    if (unfiled.length > 0 || folders.length === 0) {
        renderFolderSection(container, { id: null, name: 'Unfiled' }, unfiled, folders.length);
    }

    highlightList();
}

function renderFolderSection(container, folder, folderNotes, idx) {
    const isUnfiled = folder.id === null;
    const fkey      = isUnfiled ? 'unfiled' : folder.id;
    const collapsed = collapsedFolders.has(fkey);

    const section = document.createElement('div');
    section.className = 'folder-section' + (collapsed ? ' collapsed' : '');
    section.dataset.folderId = fkey;
    if (!isUnfiled) section.draggable = true;

    // --- Header ---
    const header = document.createElement('div');
    header.className = 'folder-header';
    header.innerHTML = `
        ${!isUnfiled ? '<span class="drag-handle" title="Drag to reorder">⠿</span>' : '<span style="width:14px;flex-shrink:0"></span>'}
        <span class="folder-toggle">${collapsed ? '▸' : '▾'}</span>
        <span class="folder-name">${escHtml(folder.name)}</span>
        <span class="folder-count">${folderNotes.length}</span>
        ${!isUnfiled ? `
        <span class="folder-actions">
            <button class="folder-btn rename-btn" title="Rename">✎</button>
            <button class="folder-btn delete-btn" title="Delete folder">✕</button>
        </span>` : ''}`;

    // Toggle collapse on header click (not on buttons/handle)
    header.addEventListener('click', e => {
        if (e.target.closest('.folder-actions, .drag-handle')) return;
        const nowCollapsed = collapsedFolders.has(fkey);
        nowCollapsed ? collapsedFolders.delete(fkey) : collapsedFolders.add(fkey);
        localStorage.setItem('collapsedFolders', JSON.stringify([...collapsedFolders]));
        section.classList.toggle('collapsed', !nowCollapsed);
        header.querySelector('.folder-toggle').textContent = nowCollapsed ? '▾' : '▸';
    });

    if (!isUnfiled) {
        header.querySelector('.rename-btn').addEventListener('click', async e => {
            e.stopPropagation();
            const name = await showPrompt('Rename Folder', 'Folder name', folder.name);
            if (name?.trim()) {
                await fetch(`api.php/folders/${folder.id}`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name: name.trim() })
                });
                loadSidebar();
            }
        });

        header.querySelector('.delete-btn').addEventListener('click', async e => {
            e.stopPropagation();
            if (!await showConfirm('Delete Folder', `Delete "${folder.name}"? Notes will be moved to Unfiled.`)) return;
            fetch(`api.php/folders/${folder.id}`, { method: 'DELETE' }).then(() => loadSidebar());
        });

        // Drag folder to reorder
        section.addEventListener('dragstart', e => {
            if (e.target.closest('.note-item')) return;
            dragType = 'folder'; dragId = folder.id;
            e.dataTransfer.effectAllowed = 'move';
            setTimeout(() => section.classList.add('dragging'), 0);
            e.stopPropagation();
        });
        section.addEventListener('dragend', () => {
            section.classList.remove('dragging');
            clearDragOver();
        });

        // Drop zone for folder reordering (between folders)
        section.addEventListener('dragover', e => {
            if (dragType !== 'folder' || folder.id === dragId) return;
            e.preventDefault(); e.stopPropagation();
            clearDragOver();
            const mid = section.getBoundingClientRect().top + section.offsetHeight / 2;
            section.classList.add(e.clientY < mid ? 'drag-over-top' : 'drag-over-bottom');
        });
        section.addEventListener('drop', e => {
            if (dragType !== 'folder') return;
            e.preventDefault(); e.stopPropagation();
            const mid = section.getBoundingClientRect().top + section.offsetHeight / 2;
            reorderFolder(dragId, folder.id, e.clientY < mid);
            clearDragOver();
        });
    }

    // Drop a note onto folder header → move into this folder
    header.addEventListener('dragover', e => {
        if (dragType !== 'note') return;
        e.preventDefault(); e.stopPropagation();
        clearDragOver();
        header.classList.add('drag-over-into');
    });
    header.addEventListener('drop', e => {
        if (dragType !== 'note') return;
        e.preventDefault(); e.stopPropagation();
        moveNoteToFolder(dragId, folder.id);
        clearDragOver();
    });

    section.appendChild(header);

    // --- Note list ---
    const ul = document.createElement('ul');
    ul.className = 'note-list';
    folderNotes.forEach(n => ul.appendChild(makeNoteEl(n, true)));

    // Drop on empty area of note list → append to this folder
    ul.addEventListener('dragover', e => { if (dragType === 'note') e.preventDefault(); });
    ul.addEventListener('drop', e => {
        if (dragType !== 'note' || e.target.closest('.note-item')) return;
        e.preventDefault();
        moveNoteToFolder(dragId, folder.id);
        clearDragOver();
    });

    section.appendChild(ul);
    container.appendChild(section);
}

function makeNoteEl(note, draggable) {
    const li = document.createElement('li');
    li.className = 'note-item';
    li.dataset.noteId = note.id;
    if (draggable) li.draggable = true;

    const handle = draggable ? '<span class="drag-handle note-drag-handle" title="Drag to reorder">⠿</span>' : '';
    li.innerHTML = `${handle}<span class="note-title">${escHtml(note.title || 'Untitled')}</span>`;

    if (note.snippet) {
        const snip = document.createElement('div');
        snip.className = 'note-snippet';
        snip.innerHTML = note.snippet;
        li.appendChild(snip);
    }

    li.addEventListener('click', e => {
        if (e.target.closest('.drag-handle')) return;
        openNote(note.id);
    });
    li.addEventListener('dblclick', e => {
        if (e.target.closest('.drag-handle')) return;
        e.preventDefault();
        inlineRenameEl(note.id, li.querySelector('.note-title'));
    });

    if (!draggable) return li;

    li.addEventListener('dragstart', e => {
        e.stopPropagation();
        dragType = 'note'; dragId = note.id;
        e.dataTransfer.effectAllowed = 'move';
        setTimeout(() => li.classList.add('dragging'), 0);
    });
    li.addEventListener('dragend', () => { li.classList.remove('dragging'); clearDragOver(); });

    li.addEventListener('dragover', e => {
        if (dragType !== 'note' || note.id == dragId) return;
        e.preventDefault(); e.stopPropagation();
        clearDragOver();
        const mid = li.getBoundingClientRect().top + li.offsetHeight / 2;
        li.classList.add(e.clientY < mid ? 'drag-over-top' : 'drag-over-bottom');
    });
    li.addEventListener('drop', e => {
        if (dragType !== 'note') return;
        e.preventDefault(); e.stopPropagation();
        const mid  = li.getBoundingClientRect().top + li.offsetHeight / 2;
        const tgt  = notes.find(n => n.id == note.id);
        reorderNote(dragId, note.id, e.clientY < mid, tgt?.folder_id ?? null);
        clearDragOver();
    });

    return li;
}

// =====================================================================
// Inline rename
// =====================================================================

async function commitRename(id, newTitle) {
    newTitle = newTitle.trim() || 'Untitled';
    if (notesCache[id]) notesCache[id].title = newTitle;
    const n = notes.find(n => n.id == id);
    if (n) n.title = newTitle;
    await fetch('api.php/' + id, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ title: newTitle }) // title-only: no 'text' key
    });
    renderTabs();
    renderSidebar();
}

function inlineRenameEl(id, labelEl) {
    const original = labelEl.textContent;
    const input = document.createElement('input');
    input.type  = 'text';
    input.value = original;
    input.style.cssText = `
        all: unset; display: inline-block; width: 100%;
        border-bottom: 1px solid var(--amber); color: inherit;
        font: inherit; background: transparent; outline: none;`;
    labelEl.replaceWith(input);
    input.focus();
    input.select();

    let committed = false;
    async function commit() {
        if (committed) return;
        committed = true;
        await commitRename(id, input.value);
    }
    function cancel() {
        if (committed) return;
        committed = true;
        renderTabs();
        renderSidebar();
    }
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter')  { e.preventDefault(); commit(); }
        if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    });
    input.addEventListener('blur', commit);
}

function clearDragOver() {
    document.querySelectorAll('.drag-over-top, .drag-over-bottom, .drag-over-into')
        .forEach(el => el.classList.remove('drag-over-top', 'drag-over-bottom', 'drag-over-into'));
}

// =====================================================================
// Reorder logic
// =====================================================================

function moveNoteToFolder(noteId, folderId) {
    const note = notes.find(n => n.id == noteId);
    if (!note) return;
    const maxOrder = Math.max(0, ...notes.filter(n => n.folder_id == folderId).map(n => n.sort_order || 0));
    note.folder_id  = folderId;
    note.sort_order = maxOrder + 1;
    renderSidebar();
    scheduleSave();
}

function reorderNote(dragNoteId, targetNoteId, before, targetFolderId) {
    const dragged = notes.find(n => n.id == dragNoteId);
    if (!dragged) return;
    dragged.folder_id = targetFolderId;
    const rest = notes.filter(n => n.folder_id == targetFolderId && n.id != dragNoteId)
                      .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0));
    const ti = rest.findIndex(n => n.id == targetNoteId);
    rest.splice(before ? ti : ti + 1, 0, dragged);
    rest.forEach((n, i) => { n.sort_order = i; });
    renderSidebar();
    scheduleSave();
}

function reorderFolder(dragFolderId, targetFolderId, before) {
    const arr = [...folders];
    const di  = arr.findIndex(f => f.id == dragFolderId);
    if (di === -1) return;
    const [dragged] = arr.splice(di, 1);
    const ti = arr.findIndex(f => f.id == targetFolderId);
    arr.splice(before ? ti : ti + 1, 0, dragged);
    arr.forEach((f, i) => { f.sort_order = i; });
    folders = arr;
    renderSidebar();
    scheduleSave();
}

function scheduleSave() {
    clearTimeout(reorderTimer);
    reorderTimer = setTimeout(() => {
        fetch('api.php/reorder', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                notes:   notes.map(n => ({ id: n.id, sort_order: n.sort_order || 0, folder_id: n.folder_id || null })),
                folders: folders.map(f => ({ id: f.id, sort_order: f.sort_order || 0 }))
            })
        });
    }, 600);
}

// =====================================================================
// Note operations (unchanged behaviour)
// =====================================================================

async function openNote(id) {
    if (!notesCache[id]) {
        const res = await fetch('api.php/' + id);
        if (!res.ok) return;
        notesCache[id] = await res.json();
    }
    if (!openNotes.includes(id)) { openNotes.push(id); renderTabs(); }
    activeId = id;
    isDirty = false;
    // Restore unsaved draft if the browser crashed before the last DB save
    const draft = localStorage.getItem('wp_draft_' + id);
    if (draft) notesCache[id].text = draft;
    tinymce.get('editor').setContent(notesCache[id].text || '');
    localStorage.setItem('currentNote', id);
    highlightTabs();
    highlightList();
    if (viewing) toggleView();
}

function renderTabs() {
    const tabs = document.getElementById('openTabs');
    tabs.innerHTML = '';
    openNotes.forEach(id => {
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-outline-secondary d-inline-flex align-items-center text-truncate me-1';
        btn.style.maxWidth = '150px';
        btn.onclick = () => openNote(id);
        btn.innerHTML = `<span class="me-1 flex-grow-1 text-truncate">${notesCache[id]?.title || ('Note ' + id)}</span><i class="fa-solid fa-xmark ms-2"></i>`;
        btn.querySelector('i').onclick = (e) => { e.stopPropagation(); closeTab(id); };
        btn.querySelector('span').addEventListener('dblclick', e => {
            e.stopPropagation();
            inlineRenameEl(id, e.currentTarget);
        });
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
            else { tinymce.get('editor').setContent(''); localStorage.removeItem('currentNote'); }
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

function highlightList() {
    document.querySelectorAll('.note-item').forEach(el => {
        el.classList.toggle('active', +el.dataset.noteId === activeId);
    });
}

// Silent background save — no sidebar refresh, retries on error
let isDirty  = false;
let isSaving = false;

async function bgSave() {
    if (!activeId || isSaving) return;
    const editor = tinymce.get('editor');
    if (!editor) return;
    const content = editor.getContent();
    isSaving = true;
    isDirty  = false;
    try {
        await fetch('api.php/' + activeId, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: notesCache[activeId]?.title || 'Untitled', text: content })
        });
        notesCache[activeId].text = content;
        localStorage.removeItem('wp_draft_' + activeId); // draft committed to DB
    } catch(e) {
        isDirty = true; // will retry next tick
    }
    isSaving = false;
}

// Manual save — also refreshes sidebar
async function saveNote() {
    await bgSave();
    loadSidebar();
}

async function toggleView() {
    if (!activeId) return;
    const viewer  = document.getElementById('viewer');
    const editor  = tinymce.get('editor');
    const btn     = document.getElementById('viewBtn');
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
        sidebar.classList.add('d-none');
        viewing = true;
    } else {
        viewer.style.display = 'none';
        editor.getContainer().style.display = '';
        btn.innerHTML = `<i class="fa-solid fa-eye me-1"></i>View`;
        document.getElementById('saveBtn').disabled = false;
        sidebar.classList.remove('d-none');
        viewing = false;
    }
}

// =====================================================================
// Event listeners
// =====================================================================

document.addEventListener('keydown', e => {
    if (e.ctrlKey && e.key === 's') { e.preventDefault(); saveNote(); }
    if (e.altKey && e.key >= '1' && e.key <= '9') {
        const idx = parseInt(e.key) - 1;
        if (openNotes[idx]) openNote(openNotes[idx]);
    }
});

document.getElementById('saveBtn').onclick = saveNote;
document.getElementById('viewBtn').onclick  = toggleView;

document.getElementById('exportBtn').onclick = async () => {
    if (!activeId) return;
    const btn     = document.getElementById('exportBtn');
    const editor  = tinymce.get('editor');
    const html    = editor ? editor.getContent() : (notesCache[activeId]?.text || '');
    const title   = notesCache[activeId]?.title || 'note';
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Exporting…';
    try {
        const res = await fetch('export.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ html, title })
        });
        if (!res.ok) throw new Error(await res.text());
        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = title + '.docx';
        a.click();
        URL.revokeObjectURL(url);
    } catch(e) {
        await showAlert('Export Failed', e.message);
    }
    btn.disabled  = false;
    btn.innerHTML = '<i class="fa-solid fa-file-word me-1"></i> Export';
};

document.getElementById('deleteBtn').onclick = async () => {
    if (!activeId) return;
    if (!await showConfirm('Delete Note', `Delete "${notesCache[activeId]?.title || 'this note'}"? This cannot be undone.`)) return;
    const idToDelete = activeId;
    const res = await fetch('api.php/' + idToDelete, { method: 'DELETE' });
    if (!res.ok) { await showAlert('Error', 'Delete failed — the database may be busy. Please try again.'); return; }
    delete notesCache[idToDelete];
    const idx = notes.findIndex(n => n.id == idToDelete);
    if (idx !== -1) notes.splice(idx, 1);
    closeTab(idToDelete);
    loadSidebar();
};

// Modal elements resolved after Bootstrap JS loads (modal HTML is below this script)
let newNoteModal, newNoteTitle, newNoteFolder;

function openNewNoteModal() {
    const activeFolderId = activeId ? (notes.find(n => n.id == activeId)?.folder_id ?? null) : null;
    newNoteFolder.innerHTML = '<option value="">Unfiled</option>' +
        folders.map(f => `<option value="${f.id}"${f.id == activeFolderId ? ' selected' : ''}>${escHtml(f.name)}</option>`).join('');
    newNoteTitle.value = '';
    newNoteModal.show();
    setTimeout(() => newNoteTitle.focus(), 200);
}

document.getElementById('newNote').onclick    = openNewNoteModal;
document.getElementById('newFolder').onclick  = async () => {
    const name = await showPrompt('New Folder', 'Folder name…');
    if (!name?.trim()) return;
    await fetch('api.php/folders/0', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name.trim() })
    });
    loadSidebar();
};

let searchTimer;
document.getElementById('searchInput').addEventListener('input', e => {
    clearTimeout(searchTimer);
    const q = e.target.value.trim();
    searchTimer = setTimeout(() => loadSidebar(q), 250);
});

// Init — deferred so Bootstrap JS and modal HTML are both available
let confirmModal, promptModal, alertModal;

function showConfirm(title, message, okLabel = 'Confirm', okClass = 'btn-danger') {
    if (!confirmModal) confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
    return new Promise(resolve => {
        document.getElementById('confirmModalTitle').textContent = title;
        document.getElementById('confirmModalBody').textContent  = message;
        const okBtn = document.getElementById('confirmModalOk');
        okBtn.textContent = okLabel;
        okBtn.className   = `btn btn-sm ${okClass}`;
        let resolved = false;
        function onOk() { resolved = true; confirmModal.hide(); resolve(true); }
        okBtn.onclick = onOk;
        document.getElementById('confirmModal').addEventListener('hidden.bs.modal', () => {
            if (!resolved) resolve(false);
        }, { once: true });
        confirmModal.show();
    });
}

function showPrompt(title, placeholder = '', defaultVal = '') {
    if (!promptModal) promptModal = new bootstrap.Modal(document.getElementById('promptModal'));
    return new Promise(resolve => {
        document.getElementById('promptModalTitle').textContent = title;
        const input = document.getElementById('promptModalInput');
        input.placeholder = placeholder;
        input.value = defaultVal;
        let resolved = false;
        function onOk() {
            if (resolved) return;
            resolved = true;
            promptModal.hide();
            resolve(input.value);
        }
        document.getElementById('promptModalOk').onclick = onOk;
        input.onkeydown = e => { if (e.key === 'Enter') onOk(); };
        document.getElementById('promptModal').addEventListener('hidden.bs.modal', () => {
            if (!resolved) resolve(null);
        }, { once: true });
        promptModal.show();
        setTimeout(() => { input.focus(); input.select(); }, 200);
    });
}

function showAlert(title, message) {
    if (!alertModal) alertModal = new bootstrap.Modal(document.getElementById('alertModal'));
    return new Promise(resolve => {
        document.getElementById('alertModalTitle').textContent = title;
        document.getElementById('alertModalBody').textContent  = message;
        document.getElementById('alertModal').addEventListener('hidden.bs.modal', () => resolve(), { once: true });
        alertModal.show();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    newNoteModal  = new bootstrap.Modal(document.getElementById('newNoteModal'));
    confirmModal  = new bootstrap.Modal(document.getElementById('confirmModal'));
    promptModal   = new bootstrap.Modal(document.getElementById('promptModal'));
    alertModal    = new bootstrap.Modal(document.getElementById('alertModal'));
    document.getElementById('newNoteModal').addEventListener('hidden.bs.modal', () => {
        document.getElementById('newFolderInlineForm').classList.add('d-none');
    });
    newNoteTitle  = document.getElementById('newNoteTitle');
    newNoteFolder = document.getElementById('newNoteFolder');

    newNoteTitle.addEventListener('keydown', e => {
        if (e.key === 'Enter') document.getElementById('newNoteConfirm').click();
    });

    // Inline folder creation inside the modal
    const inlineForm    = document.getElementById('newFolderInlineForm');
    const inlineName    = document.getElementById('newFolderInlineName');

    document.getElementById('newFolderInlineBtn').onclick = () => {
        inlineForm.classList.remove('d-none');
        inlineName.value = '';
        inlineName.focus();
    };
    document.getElementById('newFolderInlineCancel').onclick = () => {
        inlineForm.classList.add('d-none');
    };

    async function createInlineFolder() {
        const name = inlineName.value.trim();
        if (!name) return;
        const res  = await fetch('api.php/folders/0', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name })
        });
        const data = await res.json();
        // Add to main folders array and rebuild select, pre-selecting the new folder
        folders.push({ id: data.id, name: data.name, sort_order: folders.length });
        newNoteFolder.innerHTML = '<option value="">Unfiled</option>' +
            folders.map(f => `<option value="${f.id}"${f.id === data.id ? ' selected' : ''}>${escHtml(f.name)}</option>`).join('');
        inlineForm.classList.add('d-none');
    }

    document.getElementById('newFolderInlineConfirm').onclick = createInlineFolder;
    inlineName.addEventListener('keydown', e => { if (e.key === 'Enter') createInlineFolder(); });

    document.getElementById('newNoteConfirm').onclick = async () => {
        const title    = newNoteTitle.value.trim() || 'Untitled';
        const folderId = newNoteFolder.value ? +newNoteFolder.value : null;
        newNoteModal.hide();
        const res = await fetch('api.php/0', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title, text: '', folder_id: folderId })
        });
        const data = await res.json();
        await loadSidebar();
        openNote(data.id);
    };
});

// Autosave is debounced in the TinyMCE input handler above (3s after last keystroke)

loadSidebar();
const last = localStorage.getItem('currentNote');
if (last) openNote(parseInt(last));
</script>
</div><!-- end flex wrapper -->

<!-- New Note Modal -->
<div class="modal fade" id="newNoteModal" tabindex="-1" aria-labelledby="newNoteModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="newNoteModalLabel"><i class="fa-solid fa-plus me-2"></i>New Note</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="newNoteTitle">Title</label>
          <input id="newNoteTitle" type="text" class="form-control" placeholder="Note title…" autocomplete="off">
        </div>
        <div class="mb-1">
          <label class="form-label small fw-semibold" for="newNoteFolder">Folder</label>
          <div class="d-flex gap-2">
            <select id="newNoteFolder" class="form-select">
              <option value="">Unfiled</option>
            </select>
            <button type="button" id="newFolderInlineBtn" class="btn btn-outline-secondary" title="Create new folder">
              <i class="fa-solid fa-folder-plus"></i>
            </button>
          </div>
          <div id="newFolderInlineForm" class="d-none mt-2">
            <div class="input-group input-group-sm">
              <input type="text" id="newFolderInlineName" class="form-control" placeholder="Folder name…">
              <button type="button" id="newFolderInlineConfirm" class="btn btn-success" title="Create">
                <i class="fa-solid fa-check"></i>
              </button>
              <button type="button" id="newFolderInlineCancel" class="btn btn-outline-secondary" title="Cancel">
                <i class="fa-solid fa-xmark"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-success" id="newNoteConfirm">
          <i class="fa-solid fa-plus me-1"></i>Create
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Confirm Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="confirmModalTitle">Confirm</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="confirmModalBody"></div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm" id="confirmModalOk">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Prompt Modal -->
<div class="modal fade" id="promptModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="promptModalTitle">Input</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="text" id="promptModalInput" class="form-control" autocomplete="off">
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-sm btn-success" id="promptModalOk">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- Alert Modal -->
<div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="alertModalTitle">Notice</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="alertModalBody"></div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>
