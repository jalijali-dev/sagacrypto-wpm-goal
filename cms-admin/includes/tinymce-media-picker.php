<?php
declare(strict_types=1);

/**
 * TinyMCE Media Library picker — shared partial (WPM).
 *
 * Include this file just before the <script src="…/tinymce.min.js"> tag on
 * any page that uses tinymce.init(). After including it, add to tinymce.init():
 *
 *   file_picker_types: 'image',
 *   file_picker_callback: window.wpmMlPicker,
 *
 * Requirements:
 *   - $pdo (PDO)  must be defined by the including page.
 *   - app_asset_preview_url() is loaded here if not already available.
 *
 * Outputs: scoped CSS + modal HTML + JS that registers window.wpmMlPicker.
 * Does not modify media_library schema.
 */

// app_asset_preview_url() and CMS_PROJECT_ROOT are available via the chain:
//   auth.php → functions.php → cms-admin/config/app.php
/** @var \PDO $pdo */
try {
    $mceMlImages = $pdo->query(
        'SELECT id, file_name, file_path, alt_text
         FROM media_library
         WHERE is_active = 1
           AND (file_type = \'image\' OR mime_type LIKE \'image/%\')
         ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback for missing columns (schema migration not yet applied).
    $mceMlImages = $pdo->query(
        'SELECT id, file_name, file_path, alt_text
         FROM media_library
         WHERE file_type = \'image\'
         ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Used to resolve disk paths for getimagesize().
// CMS_PROJECT_ROOT is defined in cms-admin/config/app.php.
$mceMlProjectRoot = CMS_PROJECT_ROOT;
?>
<style>
/* ---- TinyMCE Media Library picker modal ---- */
/* Prefix: mce-ml-* — isolated from admin.css and gl-media-* */
#mce-ml-modal {
    position: fixed; inset: 0;
    z-index: 1400;          /* above TinyMCE dialog (~1300) */
    display: flex; align-items: center; justify-content: center;
}
#mce-ml-modal[hidden] { display: none !important; }
#mce-ml-backdrop {
    position: absolute; inset: 0;
    background: var(--modal-overlay);
}
#mce-ml-dialog {
    position: relative;
    background: var(--surface);
    border: 1px solid var(--modal-border);
    border-radius: 18px;
    box-shadow: var(--modal-shadow);
    width: min(820px, 95vw);
    max-height: 84vh;
    display: flex; flex-direction: column;
    overflow: hidden;
}
#mce-ml-head {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 16px 12px;
    border-bottom: 1px solid var(--line-subtle);
    flex-shrink: 0;
}
#mce-ml-head h3 {
    margin: 0; font-size: 15px; font-weight: 700;
    flex-shrink: 0; white-space: nowrap;
    color: var(--text);
}
#mce-ml-search {
    flex: 1; min-width: 0;
    padding: 7px 11px;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--input-bg);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
}
#mce-ml-close {
    flex-shrink: 0;
    background: transparent;
    border: 1px solid var(--line);
    border-radius: 7px;
    padding: 6px 10px;
    cursor: pointer;
    font-size: 14px;
    color: var(--muted);
    line-height: 1;
}
#mce-ml-close:hover { background: var(--navlink-hover-bg); border-color: var(--navlink-hover-border); }
#mce-ml-body {
    overflow-y: auto; flex: 1;
    padding: 10px 12px 14px;
}
.mce-ml-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 10px;
}
.mce-ml-item {
    cursor: pointer;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    overflow: hidden;
    background: var(--surface-soft);
    transition: border-color .14s ease, transform .13s ease, box-shadow .14s ease;
    outline: none;
}
.mce-ml-item:hover,
.mce-ml-item:focus {
    border-color: var(--navlink-active-border);
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm);
}
.mce-ml-item[hidden] { display: none !important; }
.mce-ml-item__img {
    display: block; width: 100%; height: 100px;
    object-fit: cover;
}
.mce-ml-item__name {
    display: block;
    font-size: 11px; color: var(--muted);
    padding: 5px 7px 3px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    border-top: 1px solid var(--line-subtle);
    background: var(--surface-soft);
}
.mce-ml-item__dims {
    display: block;
    font-size: 10px; color: var(--muted);
    padding: 0 7px 5px;
    text-align: right;
    background: var(--surface-soft);
    letter-spacing: .02em;
}
.mce-ml-empty {
    grid-column: 1 / -1;
    padding: 24px; text-align: center;
    color: var(--muted); font-size: 14px;
}
/* ---- Upload dropzone (23 Aug 2026) ---- */
#mce-ml-dropzone {
    flex-shrink: 0;
    margin: 0 12px 10px;
    padding: 14px 12px;
    border: 1.5px dashed var(--line);
    border-radius: 10px;
    text-align: center;
    font-size: 12.5px;
    color: var(--muted);
    cursor: pointer;
    transition: border-color .14s ease, background .14s ease, color .14s ease;
}
#mce-ml-dropzone:hover,
#mce-ml-dropzone:focus-visible {
    border-color: var(--navlink-active-border);
    color: var(--text);
}
#mce-ml-dropzone.is-dragover {
    border-color: var(--navlink-active-border);
    background: var(--surface-soft);
    color: var(--text);
}
#mce-ml-dropzone strong { color: var(--text); font-weight: 700; }
#mce-ml-dropzone-status {
    display: none;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
#mce-ml-dropzone.is-busy #mce-ml-dropzone-idle { display: none; }
#mce-ml-dropzone.is-busy #mce-ml-dropzone-status { display: flex; }
#mce-ml-dropzone-spinner {
    width: 14px; height: 14px;
    border: 2px solid var(--line);
    border-top-color: var(--navlink-active-border);
    border-radius: 50%;
    animation: mce-ml-spin .7s linear infinite;
}
@keyframes mce-ml-spin { to { transform: rotate(360deg); } }
#mce-ml-dropzone-error {
    display: none;
    margin: -4px 12px 10px;
    padding: 8px 10px;
    border-radius: 8px;
    background: rgba(220, 38, 38, .1);
    border: 1px solid rgba(220, 38, 38, .3);
    color: #dc2626;
    font-size: 12.5px;
}
#mce-ml-dropzone-error.is-visible { display: block; }
</style>

<!-- TinyMCE Media Library picker modal -->
<div id="mce-ml-modal" hidden role="dialog" aria-modal="true" aria-labelledby="mce-ml-title">
    <div id="mce-ml-backdrop"></div>
    <div id="mce-ml-dialog">

        <div id="mce-ml-head">
            <h3 id="mce-ml-title">Select from Media Library</h3>
            <input type="search" id="mce-ml-search"
                   placeholder="Search images…"
                   autocomplete="off">
            <button type="button" id="mce-ml-close" aria-label="Close">✕</button>
        </div>

        <div id="mce-ml-dropzone" role="button" tabindex="0" aria-label="Upload a new image">
            <span id="mce-ml-dropzone-idle"><strong>Click to upload</strong> or drag &amp; drop an image here (max 5 MB)</span>
            <span id="mce-ml-dropzone-status"><span id="mce-ml-dropzone-spinner" aria-hidden="true"></span> Uploading…</span>
        </div>
        <input type="file" id="mce-ml-file-input" accept="image/*" hidden>
        <div id="mce-ml-dropzone-error" role="alert"></div>

        <div id="mce-ml-body">
            <div class="mce-ml-grid">
                <?php if ($mceMlImages === []) : ?>
                    <p class="mce-ml-empty">No images found in the Media Library.</p>
                <?php endif; ?>
                <?php foreach ($mceMlImages as $mceImg) : ?>
                    <?php
                    $mceId   = (int)    $mceImg['id'];
                    $mceName = (string) $mceImg['file_name'];
                    $mcePath = (string) $mceImg['file_path'];
                    $mceAlt  = (string) ($mceImg['alt_text'] ?? '');
                    $mceSrc  = app_asset_preview_url($mcePath);

                    // Detect real image dimensions from disk.
                    // Skips external URLs and files that cannot be resolved.
                    // getimagesize() supports JPEG, PNG, GIF, WebP (PHP 7.1+).
                    $mceW = 0;
                    $mceH = 0;
                    // H-3: only read from disk when the stored path safely resolves
                    // inside the uploads directory (blocks traversal, incl. legacy rows).
                    $diskPath = app_safe_media_disk_path($mcePath, $mceMlProjectRoot);
                    if ($diskPath !== null && is_file($diskPath)) {
                        $dimResult = @getimagesize($diskPath);
                        if (is_array($dimResult) && $dimResult[0] > 0 && $dimResult[1] > 0) {
                            $mceW = (int) $dimResult[0];
                            $mceH = (int) $dimResult[1];
                        }
                    }
                    ?>
                    <div class="mce-ml-item"
                         role="button"
                         tabindex="0"
                         data-src="<?=  htmlspecialchars($mceSrc,             ENT_QUOTES, 'UTF-8') ?>"
                         data-path="<?= htmlspecialchars($mcePath,            ENT_QUOTES, 'UTF-8') ?>"
                         data-alt="<?=  htmlspecialchars($mceAlt,             ENT_QUOTES, 'UTF-8') ?>"
                         data-name="<?= htmlspecialchars(strtolower($mceName), ENT_QUOTES, 'UTF-8') ?>"
                         data-width="<?= $mceW ?>"
                         data-height="<?= $mceH ?>"
                         title="<?= htmlspecialchars($mceName, ENT_QUOTES, 'UTF-8') ?>">
                        <img class="mce-ml-item__img"
                             src="<?= htmlspecialchars($mceSrc, ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($mceAlt ?: $mceName, ENT_QUOTES, 'UTF-8') ?>"
                             loading="lazy"
                             onerror="this.style.display='none'">
                        <span class="mce-ml-item__name"><?= htmlspecialchars($mceName, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($mceW > 0 && $mceH > 0) : ?>
                            <span class="mce-ml-item__dims"><?= $mceW ?> × <?= $mceH ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    var modal    = document.getElementById('mce-ml-modal');
    var backdrop = document.getElementById('mce-ml-backdrop');
    var search   = document.getElementById('mce-ml-search');
    var closeBtn = document.getElementById('mce-ml-close');
    var grid     = document.querySelector('.mce-ml-grid');
    var dropzone = document.getElementById('mce-ml-dropzone');
    var fileInput = document.getElementById('mce-ml-file-input');
    var errorBox = document.getElementById('mce-ml-dropzone-error');
    // Mutable array (NOT the static NodeList querySelectorAll returns) —
    // an uploaded item gets pushed in here so search/select wiring covers
    // it exactly like a page-load-rendered one, see wireItem() below.
    var items = Array.prototype.slice.call(document.querySelectorAll('.mce-ml-item'));

    if (!modal) return;

    var currentCallback = null;
    var CSRF_TOKEN = '<?= cms_csrf_token() ?>';
    var UPLOAD_URL = '<?= cms_esc(cms_api_href('media-upload.php')) ?>';
    var MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB — matches cms_handle_media_upload()'s image limit

    /* ---- open / close ---- */
    function openModal() {
        modal.hidden = false;
        if (search) { search.value = ''; filterItems(''); search.focus(); }
    }
    function closeModal() {
        modal.hidden = true;
        currentCallback = null;
    }

    /* ---- search filter ---- */
    function filterItems(q) {
        q = q.toLowerCase().trim();
        items.forEach(function (item) {
            if (!q) { item.hidden = false; return; }
            var name = (item.getAttribute('data-name') || '').toLowerCase();
            item.hidden = name.indexOf(q) === -1;
        });
    }

    /* ---- select item → fill TinyMCE Source, Alt, Width, Height fields ---- */
    function selectItem(item) {
        if (!currentCallback) return;

        // data-src  = browser-valid URL (app_asset_preview_url output).
        // data-path = stored DB path — kept for reference but NOT passed to TinyMCE,
        //             because TinyMCE uses the URL to preview the image in the dialog.
        var src  = item.getAttribute('data-src')    || item.getAttribute('data-path') || '';
        var alt  = item.getAttribute('data-alt')    || '';
        var w    = parseInt(item.getAttribute('data-width')  || '0', 10);
        var h    = parseInt(item.getAttribute('data-height') || '0', 10);

        // Cap display width at 600 px, preserve aspect ratio.
        var MAX_W = 600;
        if (w > MAX_W) {
            h = h > 0 ? Math.round(h * MAX_W / w) : 0;
            w = MAX_W;
        }

        // Build the meta object TinyMCE uses to pre-fill dialog fields.
        // Width/height omitted when 0 so TinyMCE can infer them from the loaded image.
        var imgMeta = { title: alt };
        if (w > 0) { imgMeta.width  = String(w); }
        if (h > 0) { imgMeta.height = String(h); }

        currentCallback(src, imgMeta);
        closeModal();
    }

    /* ---- event wires ---- */
    if (closeBtn)  { closeBtn.addEventListener('click', closeModal); }
    if (backdrop)  { backdrop.addEventListener('click', closeModal); }

    document.addEventListener('keydown', function (e) {
        if (!modal.hidden && (e.key === 'Escape' || e.key === 'Esc')) { closeModal(); }
    });

    if (search) {
        search.addEventListener('input', function () { filterItems(search.value); });
    }

    // Wires click + keyboard (Enter/Space, role="button"+tabindex a11y) —
    // shared by every item, whether PHP-rendered at page load or injected
    // after an upload below, so both behave identically.
    function wireItem(item) {
        item.addEventListener('click', function () { selectItem(item); });
        item.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                selectItem(item);
            }
        });
    }

    items.forEach(wireItem);

    /* ---- upload (drag & drop + click-to-browse), 23 Aug 2026 ---- */
    function showUploadError(message) {
        errorBox.textContent = message;
        errorBox.classList.add('is-visible');
    }
    function clearUploadError() {
        errorBox.textContent = '';
        errorBox.classList.remove('is-visible');
    }

    /**
     * Builds one .mce-ml-item node with the exact same markup/data-
     * attributes as the PHP-rendered items above (see the foreach in this
     * file's HTML section) — so an item from a fresh upload is
     * indistinguishable from one that existed at page load, and works
     * with filterItems()/selectItem()/wireItem() unchanged.
     */
    function buildItemNode(data) {
        var item = document.createElement('div');
        item.className = 'mce-ml-item';
        item.setAttribute('role', 'button');
        item.setAttribute('tabindex', '0');
        item.setAttribute('data-src', data.thumb_url || '');
        item.setAttribute('data-path', data.file_path || '');
        item.setAttribute('data-alt', '');
        item.setAttribute('data-name', (data.file_name || '').toLowerCase());
        item.setAttribute('data-width', String(data.width || 0));
        item.setAttribute('data-height', String(data.height || 0));
        item.setAttribute('title', data.file_name || '');

        var img = document.createElement('img');
        img.className = 'mce-ml-item__img';
        img.src = data.thumb_url || '';
        img.alt = data.file_name || '';
        img.loading = 'lazy';
        img.onerror = function () { img.style.display = 'none'; };
        item.appendChild(img);

        var nameEl = document.createElement('span');
        nameEl.className = 'mce-ml-item__name';
        nameEl.textContent = data.file_name || '';
        item.appendChild(nameEl);

        if (data.width > 0 && data.height > 0) {
            var dimsEl = document.createElement('span');
            dimsEl.className = 'mce-ml-item__dims';
            dimsEl.textContent = data.width + ' × ' + data.height;
            item.appendChild(dimsEl);
        }

        return item;
    }

    function uploadFile(file) {
        clearUploadError();

        if (file.type.indexOf('image/') !== 0) {
            showUploadError('Only image files can be uploaded here.');
            return;
        }
        if (file.size > MAX_UPLOAD_BYTES) {
            showUploadError('File exceeds the 5 MB limit.');
            return;
        }

        dropzone.classList.add('is-busy');

        var formData = new FormData();
        formData.append('media_file', file);
        formData.append('csrf_token', CSRF_TOKEN);

        fetch(UPLOAD_URL, { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                dropzone.classList.remove('is-busy');
                if (!data || !data.success) {
                    showUploadError((data && data.error) ? data.error : 'Upload failed.');
                    return;
                }
                // New upload first (matches the page's own ORDER BY id DESC).
                var item = buildItemNode(data);
                wireItem(item);
                if (grid) { grid.insertBefore(item, grid.firstChild); }
                items.unshift(item);
                if (search) { search.value = ''; filterItems(''); }
                // Auto-select — the whole point of uploading from inside
                // the picker is not having to go find it again afterward.
                // Dispatch a real click instead of calling selectItem(item)
                // directly: this modal is also opened standalone by
                // pages.php's own "Choose from Media Library" path-picker
                // (cms-admin/pages/pages.php), which listens for clicks on
                // .mce-ml-item via its own document-level delegated
                // listener, completely separate from this file's
                // currentCallback/selectItem() TinyMCE flow. A real click
                // bubbles to both listeners, so upload+auto-select works
                // whether the modal was opened from TinyMCE or from a
                // Featured/OG image field button.
                item.click();
            })
            .catch(function () {
                dropzone.classList.remove('is-busy');
                showUploadError('Upload failed — check your connection and try again.');
            });
    }

    if (dropzone && fileInput) {
        dropzone.addEventListener('click', function () { fileInput.click(); });
        dropzone.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                fileInput.click();
            }
        });
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                uploadFile(fileInput.files[0]);
            }
            fileInput.value = ''; // allow re-selecting the same file later
        });

        ['dragenter', 'dragover'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (evt) {
            dropzone.addEventListener(evt, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('is-dragover');
            });
        });
        dropzone.addEventListener('drop', function (e) {
            var files = e.dataTransfer && e.dataTransfer.files;
            if (files && files[0]) { uploadFile(files[0]); }
        });
    }

    /**
     * TinyMCE file_picker_callback.
     * Register as: file_picker_callback: window.wpmMlPicker
     *
     * @param {Function} callback  Call with (url, meta) to fill the Source field.
     * @param {string}   value     Current value of the Source field.
     * @param {Object}   meta      { filetype: 'image'|'media'|'file' }
     */
    window.wpmMlPicker = function (callback, value, meta) {
        if (!meta || meta.filetype !== 'image') return;
        currentCallback = callback;
        openModal();
    };
})();

// ---- Shared TinyMCE content styles — consumed by each page's tinymce.init() ----
// These styles apply inside the TinyMCE iframe so images look correct while editing.
window.wpmMlContentStyle =
    'body { display: flow-root; }' +
    // Base: unclassified images get centred-ish appearance in the editor
    'img { display:block; max-width:600px; width:auto; height:auto; border-radius:12px; margin:24px auto; }' +
    // Alignment classes — must mirror assets/css/style.css exactly
    '.img-center { display:block; float:none; width:auto; max-width:700px; height:auto; margin:24px auto; border-radius:12px; }' +
    '.img-full   { display:block; float:none; width:100%; max-width:900px; height:auto; margin:24px auto; border-radius:12px; }' +
    '.img-left   { float:left;  width:280px; max-width:40%; height:auto; margin:0 24px 16px 0; border-radius:12px; }' +
    '.img-right  { float:right; width:280px; max-width:40%; height:auto; margin:0 0 16px 24px; border-radius:12px; }' +
    '.img-left~.img-left,.img-left~.img-right,.img-right~.img-left,.img-right~.img-right{clear:both}' +
    '@media (max-width:640px){' +
    '  .img-center,.img-full,.img-left,.img-right{float:none;display:block;width:100%;max-width:100%;margin:20px 0;}' +
    '}';

// ---- Editor setup hook — add to tinymce.init() setup function ----
// Adds an inline style attribute to newly inserted/selected images that do not
// already have one, so the styled appearance is preserved in the saved HTML.
window.wpmMlSetupEditor = function (editor) {
    // Only applied to images that carry NO alignment class (fallback insurance).
    // Images with img-center/img-full/img-left/img-right get their appearance
    // entirely from the CSS class — adding inline styles would override the class.
    var BASE_STYLE = 'height:auto;border-radius:12px';
    var ALIGN_RE   = /\bimg-(center|full|left|right)\b/;
    var ready = false;

    editor.on('init', function () { ready = true; });

    editor.on('NodeChange', function (e) {
        if (!ready) return;
        var el = e.element;
        if (el && el.nodeName === 'IMG' && !el.getAttribute('style')) {
            if (!ALIGN_RE.test(el.getAttribute('class') || '')) {
                editor.dom.setAttrib(el, 'style', BASE_STYLE);
            }
        }
    });
};
</script>
