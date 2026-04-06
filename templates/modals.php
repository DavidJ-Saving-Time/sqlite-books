<!-- Reusable Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" id="confirmModalHeader">
        <h5 class="modal-title" id="confirmModalTitle"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="confirmModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmModalOk">Confirm</button>
      </div>
    </div>
  </div>
</div>

<!-- Send to Device Result Modal -->
<div class="modal fade" id="sendResultModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" id="sendResultHeader">
        <h5 class="modal-title" id="sendResultTitle">Send to Device</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="sendResultBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Open Library Metadata Modal -->
<div class="modal fade" id="openLibraryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Open Library Results</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="openLibraryResults">Loading...</div>
      </div>
    </div>
  </div>
</div>

<!-- Recommendations Modal -->
<div class="modal fade" id="recModal" tabindex="-1" aria-labelledby="recModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="recModalLabel">Recommendations</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="recModalContent"></div>
      </div>
      <div class="modal-footer">
        <span id="recModalStatus" class="text-muted small me-auto"></span>
        <button type="button" class="btn btn-outline-secondary d-none" id="recModalGenerate">
          <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Generate Recommendations
        </button>
        <button type="button" class="btn btn-outline-secondary d-none" id="recModalRegenerate">
          <i class="fa-solid fa-arrows-rotate me-1"></i> Regenerate
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Description Edit Modal -->
<div class="modal fade" id="descModal" tabindex="-1" aria-labelledby="descModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="descModalLabel">Description</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="form-control p-0" style="height:420px; overflow:hidden;">
          <textarea id="descModalEditor"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <span id="descModalStatus" class="text-muted small me-auto"></span>
        <button type="button" class="btn btn-outline-secondary" id="descModalSynopsis">
          <i class="fa-solid fa-wand-magic-sparkles me-1"></i> Generate Synopsis
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="descModalSave">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Author Info Modal -->
<div class="modal fade" id="authorModal" tabindex="-1" aria-labelledby="authorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="authorModalLabel">Author</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="authorModalBody">
        <div class="d-flex justify-content-center py-4">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading…</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a id="authorModalFilterLink" href="#" class="btn btn-primary me-auto">
          <i class="fa-solid fa-filter me-1"></i> Filter by this author
        </a>
        <span id="authorModalBioStatus" class="text-muted small me-2"></span>
        <button type="button" class="btn btn-secondary d-none" id="authorModalSaveBio">
          <i class="fa-solid fa-floppy-disk me-1"></i> Save Bio
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
