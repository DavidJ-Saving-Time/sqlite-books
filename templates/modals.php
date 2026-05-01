<!-- LOC Catalog Lookup Modal -->
<div class="modal fade" id="locModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa-solid fa-landmark me-2"></i>Library of Congress Catalog</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="locModalBody" style="max-height:70vh;overflow-y:auto"></div>
    </div>
  </div>
</div>

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

<!-- Description View Modal (read-only, opened from context menu) -->
<div class="modal fade" id="descViewModal" tabindex="-1" aria-labelledby="descViewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="descViewModalLabel">Description</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="descViewModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Reviews View Modal -->
<div class="modal fade" id="reviewsModal" tabindex="-1" aria-labelledby="reviewsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="reviewsModalLabel">Reviews</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="reviewsModalBody"></div>
      <div class="modal-footer">
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

<!-- Wikipedia Book Modal -->
<div class="modal fade" id="wikiBookModal" tabindex="-1" aria-labelledby="wikiBookModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="wikiBookModalLabel">Wikipedia</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="wikiBookModalBody">
        <div class="d-flex justify-content-center py-4">
          <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading…</span>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a id="wikiBookModalLink" href="#" target="_blank" class="btn btn-outline-primary me-auto d-none">
          <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Full article
        </a>
        <span id="wikiBookModalStatus" class="text-muted small me-auto"></span>
        <button type="button" class="btn btn-outline-secondary d-none" id="wikiBookModalRefetch">
          <i class="fa-solid fa-arrows-rotate me-1"></i> Re-fetch
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Author Info Modal -->
<div class="modal fade" id="authorModal" tabindex="-1" aria-labelledby="authorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
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

<!-- Similar Books Modal -->
<div class="modal fade" id="similarModal" tabindex="-1" aria-labelledby="similarModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="similarModalLabel"><i class="fa-solid fa-list-ul me-2"></i>Similar Books</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="similarModalBody">
        <div class="d-flex justify-content-center py-4">
          <div class="spinner-border text-secondary" role="status"><span class="visually-hidden">Loading…</span></div>
        </div>
      </div>
      <div class="modal-footer">
        <span id="similarModalStatus" class="text-muted small me-auto"></span>
        <button type="button" class="btn btn-outline-secondary" id="similarModalRefresh" style="display:none">
          <i class="fa-solid fa-rotate me-1"></i>Refresh
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- GR Dual Cover Modal -->
<div class="modal fade" id="grCoverModal" tabindex="-1" aria-labelledby="grCoverModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="grCoverModalLabel"><i class="fa-brands fa-goodreads me-2"></i>GR Covers</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="grCoverModalBody">
        <div id="grCoverContent">
          <div class="row g-4">
            <div class="col-6 text-center" id="grCoverLocalCol"></div>
            <div class="col-6 text-center" id="grCoverCdnCol"></div>
          </div>
        </div>
        <div id="grCoverError" class="text-danger py-3 text-center" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Cover Download Preview Modal -->
<div class="modal fade" id="coverDlModal" tabindex="-1" aria-labelledby="coverDlModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="coverDlModalLabel"><i class="fa-solid fa-image me-2"></i>Download Cover</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center" id="coverDlModalBody">
        <div id="coverDlSpinner" class="py-4">
          <div class="spinner-border text-secondary" role="status"></div>
          <div class="text-muted small mt-2">Fetching cover…</div>
        </div>
        <div id="coverDlPreview" style="display:none">
          <img id="coverDlImg" src="" alt="Cover preview"
               style="max-width:100%;max-height:420px;border-radius:0.35rem;box-shadow:0 2px 12px rgba(0,0,0,0.35)">
          <div class="mt-3 d-flex justify-content-center gap-3 flex-wrap">
            <span id="coverDlSource" class="badge fs-6"></span>
            <span id="coverDlDims"   class="text-muted small align-self-center"></span>
            <span id="coverDlSize"   class="text-muted small align-self-center"></span>
          </div>
        </div>
        <div id="coverDlError" class="text-danger py-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="coverDlSaveBtn" style="display:none">
          <i class="fa-solid fa-floppy-disk me-1"></i>Use this cover
        </button>
      </div>
    </div>
  </div>
</div>
