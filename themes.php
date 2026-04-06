<?php
require_once 'db.php';
requireLogin();

// ── Helpers ──────────────────────────────────────────────────────────────────

function hexToRgb(string $hex): string {
    $hex = ltrim($hex, '#');
    return hexdec(substr($hex, 0, 2)) . ', '
         . hexdec(substr($hex, 2, 2)) . ', '
         . hexdec(substr($hex, 4, 2));
}

function rgbToHex(string $rgb): string {
    $parts = array_map('trim', explode(',', $rgb));
    if (count($parts) !== 3) return '#0d6efd';
    return sprintf('#%02x%02x%02x', (int)$parts[0], (int)$parts[1], (int)$parts[2]);
}

// ── Presets ───────────────────────────────────────────────────────────────────

$presets = [
    'light' => [
        'name'       => 'Light',
        'bootswatch' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
        'vars'       => [
            '--accent'             => '#fd8c00',
            '--row-stripe-a'       => 'transparent',
            '--row-stripe-b'       => 'rgba(0,0,0,0.04)',
            '--metabar-bg'         => '#f5f5f5',
            '--metabar-border'     => '#cfcfcf',
            '--metabar-label'      => '#7a7a7a',
            '--bs-border-color'    => '#dee2e6',
            '--bs-link-color-rgb'  => '13, 110, 253',
            '--form-control-color' => '#212529',
            '--form-control-bg'    => '#ffffff',
        ],
    ],
    'dark' => [
        'name'       => 'Dark (Darkly)',
        'bootswatch' => 'https://cdn.jsdelivr.net/npm/bootswatch@5/dist/darkly/bootstrap.min.css',
        'vars'       => [
            '--accent'             => '#fd8c00',
            '--row-stripe-a'       => 'transparent',
            '--row-stripe-b'       => 'rgba(255,255,255,0.05)',
            '--metabar-bg'         => '#2a2a2e',
            '--metabar-border'     => '#444444',
            '--metabar-label'      => '#999999',
            '--bs-border-color'    => '#444444',
            '--bs-link-color-rgb'  => '110, 168, 254',
            '--form-control-color' => '#dee2e6',
            '--form-control-bg'    => '#303030',
        ],
    ],
    'sepia' => [
        'name'       => 'Sepia',
        'bootswatch' => 'https://cdn.jsdelivr.net/npm/bootswatch@5/dist/journal/bootstrap.min.css',
        'vars'       => [
            '--accent'             => '#8b6914',
            '--row-stripe-a'       => 'transparent',
            '--row-stripe-b'       => 'rgba(0,0,0,0.03)',
            '--metabar-bg'         => '#f4ecd8',
            '--metabar-border'     => '#c8a97a',
            '--metabar-label'      => '#8b6914',
            '--bs-border-color'    => '#c8a97a',
            '--bs-link-color-rgb'  => '139, 105, 20',
            '--form-control-color' => '#3b2f1e',
            '--form-control-bg'    => '#fdf6e3',
        ],
    ],
    'slate' => [
        'name'       => 'Slate',
        'bootswatch' => 'https://cdn.jsdelivr.net/npm/bootswatch@5/dist/slate/bootstrap.min.css',
        'vars'       => [
            '--accent'             => '#ea7844',
            '--row-stripe-a'       => 'transparent',
            '--row-stripe-b'       => 'rgba(255,255,255,0.04)',
            '--metabar-bg'         => '#3a3f44',
            '--metabar-border'     => '#555',
            '--metabar-label'      => '#aaa',
            '--bs-border-color'    => '#555',
            '--bs-link-color-rgb'  => '234, 120, 68',
            '--form-control-color' => '#c8c8c8',
            '--form-control-bg'    => '#3a3f44',
        ],
    ],
];

$allowedHosts = ['cdn.jsdelivr.net', 'bootswatch.com', 'stackpath.bootstrapcdn.com', 'maxcdn.bootstrapcdn.com'];

// ── POST handler ──────────────────────────────────────────────────────────────

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['preset']) && isset($presets[$_POST['preset']])) {
        // Apply a preset wholesale
        $p = $presets[$_POST['preset']];
        setUserPreference(currentUser(), 'THEME_JSON',
            json_encode(['bootswatch' => $p['bootswatch'], 'vars' => $p['vars']]));
        $message = 'Preset "' . $p['name'] . '" applied.';

    } else {
        // Build from individual form fields
        $bsw    = trim($_POST['bootswatch'] ?? '');
        $parsed = parse_url($bsw);
        if (($parsed['scheme'] ?? '') !== 'https' || !in_array($parsed['host'] ?? '', $allowedHosts, true)) {
            $bsw = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
        }

        $vars = [];

        $accent = trim($_POST['accent'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $vars['--accent'] = $accent;
        }

        foreach (['stripe_a' => '--row-stripe-a', 'stripe_b' => '--row-stripe-b'] as $field => $prop) {
            $stripe = trim($_POST[$field] ?? '');
            if ($stripe !== '' && preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]+\)|transparent)$/', $stripe)) {
                $vars[$prop] = $stripe;
            }
        }

        foreach ([
            'metabar_bg'         => '--metabar-bg',
            'metabar_border'     => '--metabar-border',
            'metabar_label'      => '--metabar-label',
            'border_color'       => '--bs-border-color',
            'form_control_color' => '--form-control-color',
            'form_control_bg'    => '--form-control-bg',
        ] as $field => $prop) {
            $val = trim($_POST[$field] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
                $vars[$prop] = $val;
            }
        }

        $linkHex = trim($_POST['link_color'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $linkHex)) {
            $vars['--bs-link-color-rgb'] = hexToRgb($linkHex);
        }

        setUserPreference(currentUser(), 'THEME_JSON',
            json_encode(['bootswatch' => $bsw, 'vars' => $vars]));
        $message = 'Theme saved.';
    }
}

// ── Load current values ───────────────────────────────────────────────────────

$json    = getUserPreference(currentUser(), 'THEME_JSON', getPreference('THEME_JSON', ''));
$current = ($json !== '') ? (json_decode($json, true) ?: []) : [];

$currentBsw    = $current['bootswatch'] ?? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
$vars          = $current['vars'] ?? [];

$fAccent        = $vars['--accent']            ?? '#fd8c00';
$fStripeA       = $vars['--row-stripe-a']       ?? 'transparent';
$fStripeB       = $vars['--row-stripe-b']       ?? 'rgba(0,0,0,0.04)';
$fMetabarBg     = $vars['--metabar-bg']         ?? '#f5f5f5';
$fMetabarBorder = $vars['--metabar-border']     ?? '#cfcfcf';
$fMetabarLabel  = $vars['--metabar-label']      ?? '#7a7a7a';
$fBorderColor   = $vars['--bs-border-color']    ?? '#dee2e6';
$fLinkRgb          = $vars['--bs-link-color-rgb']   ?? '13, 110, 253';
$fLinkHex          = rgbToHex($fLinkRgb);
$fStripeAHex       = preg_match('/^#[0-9a-fA-F]{6}$/', $fStripeA) ? $fStripeA : '#ffffff';
$fStripeBHex       = preg_match('/^#[0-9a-fA-F]{6}$/', $fStripeB) ? $fStripeB : '#f0f0f0';
$fFormControlColor = $vars['--form-control-color']  ?? '#212529';
$fFormControlBg    = $vars['--form-control-bg']     ?? '#ffffff';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Theme Settings</title>
  <link rel="stylesheet" href="/theme.css.php">
  <link rel="stylesheet" href="/css/all.min.css">
  <script src="js/theme.js" defer></script>
</head>
<body class="pt-5">
<?php include 'navbar.php'; ?>
<div class="container py-4" style="max-width: 680px;">
  <h1 class="mb-1">Theme Settings</h1>
  <p class="text-muted mb-4 small"><a href="preferences.php">← Preferences</a></p>

  <?php if ($message): ?>
    <div class="alert alert-success py-2"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <!-- ── Presets ──────────────────────────────────────────────────────── -->
  <h6 class="text-uppercase text-muted small fw-semibold mb-2 mt-3">Quick presets</h6>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($presets as $key => $p): ?>
      <form method="post" style="display:contents">
        <input type="hidden" name="preset" value="<?= htmlspecialchars($key) ?>">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
          <?= htmlspecialchars($p['name']) ?>
        </button>
      </form>
    <?php endforeach; ?>
  </div>

  <hr class="mb-4">

  <!-- ── Main form ────────────────────────────────────────────────────── -->
  <form method="post" id="themeForm">
    <!-- Hidden field holding the selected Bootswatch URL; updated by theme.js -->
    <input type="hidden" name="bootswatch" id="bootswatchUrl" value="<?= htmlspecialchars($currentBsw) ?>">

    <!-- Bootswatch selector -->
    <div class="mb-4">
      <label for="themeSelect" class="form-label fw-semibold">Bootstrap theme</label>
      <select id="themeSelect" class="form-select" style="max-width: 22rem;"></select>
      <div class="form-text">Populated from the Bootswatch API. Changing this previews live.</div>
    </div>

    <h6 class="text-uppercase text-muted small fw-semibold mb-3">Colour overrides</h6>

    <!-- Accent -->
    <div class="mb-3 row align-items-center">
      <label for="f_accent" class="col-sm-4 col-form-label">Accent</label>
      <div class="col-sm-8">
        <input type="color" id="f_accent" name="accent" class="form-control form-control-color"
               value="<?= htmlspecialchars($fAccent) ?>"
               data-var="--accent">
        <div class="form-text">Links and highlights on the book list</div>
      </div>
    </div>

    <!-- Border -->
    <div class="mb-3 row align-items-center">
      <label for="f_border" class="col-sm-4 col-form-label">Border <code class="small">--bs-border-color</code></label>
      <div class="col-sm-8">
        <input type="color" id="f_border" name="border_color" class="form-control form-control-color"
               value="<?= htmlspecialchars($fBorderColor) ?>"
               data-var="--bs-border-color">
      </div>
    </div>

    <!-- Form controls -->
    <div class="mb-3 row align-items-center">
      <label class="col-sm-4 col-form-label">Form controls</label>
      <div class="col-sm-8">
        <div class="d-flex flex-wrap gap-3 mb-1">
          <div class="d-flex align-items-center gap-1">
            <input type="color" id="f_form_control_color" name="form_control_color" class="form-control form-control-color"
                   value="<?= htmlspecialchars($fFormControlColor) ?>"
                   data-var-component="form-control-color">
            <label for="f_form_control_color" class="form-text mb-0">Text colour</label>
          </div>
          <div class="d-flex align-items-center gap-1">
            <input type="color" id="f_form_control_bg" name="form_control_bg" class="form-control form-control-color"
                   value="<?= htmlspecialchars($fFormControlBg) ?>"
                   data-var-component="form-control-bg">
            <label for="f_form_control_bg" class="form-text mb-0">Background</label>
          </div>
        </div>
        <div class="form-text">Applies to <code>.form-control</code>, <code>.form-select</code>, and checkboxes</div>
      </div>
    </div>

    <!-- Link colour -->
    <div class="mb-3 row align-items-center">
      <label for="f_link" class="col-sm-4 col-form-label">Link colour <code class="small">--bs-link-color-rgb</code></label>
      <div class="col-sm-8">
        <input type="color" id="f_link" name="link_color" class="form-control form-control-color"
               value="<?= htmlspecialchars($fLinkHex) ?>"
               data-live="link">
        <div class="form-text">Stored as RGB integers; converted automatically on save</div>
      </div>
    </div>

    <!-- Row stripes -->
    <div class="mb-3 row align-items-start">
      <label class="col-sm-4 col-form-label">Row stripes</label>
      <div class="col-sm-8">
        <div class="d-flex flex-column gap-2">
          <div>
            <label class="form-text mb-1">Row A (odd)</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" id="f_stripe_a_picker" class="form-control form-control-color"
                     value="<?= htmlspecialchars($fStripeAHex) ?>">
              <input type="text" id="f_stripe_a" name="stripe_a" class="form-control"
                     value="<?= htmlspecialchars($fStripeA) ?>"
                     placeholder="transparent"
                     data-var="--row-stripe-a">
            </div>
          </div>
          <div>
            <label class="form-text mb-1">Row B (even)</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" id="f_stripe_b_picker" class="form-control form-control-color"
                     value="<?= htmlspecialchars($fStripeBHex) ?>">
              <input type="text" id="f_stripe_b" name="stripe_b" class="form-control"
                     value="<?= htmlspecialchars($fStripeB) ?>"
                     placeholder="rgba(0,0,0,0.04)"
                     data-var="--row-stripe-b">
            </div>
          </div>
        </div>
        <div class="form-text mt-1">Picker sets hex · type <code>rgba()</code> for transparency · use <code>transparent</code> for no colour</div>
      </div>
    </div>

    <!-- Metadata bar -->
    <div class="mb-4 row">
      <label class="col-sm-4 col-form-label">Metadata bar</label>
      <div class="col-sm-8">
        <div class="d-flex flex-wrap gap-3 mb-1">
          <div class="d-flex align-items-center gap-1">
            <input type="color" id="f_metabar_bg" name="metabar_bg" class="form-control form-control-color"
                   value="<?= htmlspecialchars($fMetabarBg) ?>"
                   data-var="--metabar-bg">
            <label for="f_metabar_bg" class="form-text mb-0">Background</label>
          </div>
          <div class="d-flex align-items-center gap-1">
            <input type="color" id="f_metabar_border" name="metabar_border" class="form-control form-control-color"
                   value="<?= htmlspecialchars($fMetabarBorder) ?>"
                   data-var="--metabar-border">
            <label for="f_metabar_border" class="form-text mb-0">Border</label>
          </div>
          <div class="d-flex align-items-center gap-1">
            <input type="color" id="f_metabar_label" name="metabar_label" class="form-control form-control-color"
                   value="<?= htmlspecialchars($fMetabarLabel) ?>"
                   data-var="--metabar-label">
            <label for="f_metabar_label" class="form-text mb-0">Label text</label>
          </div>
        </div>
        <div class="form-text">Genre / shelf / status bar on each book row</div>
      </div>
    </div>

    <button type="submit" class="btn btn-primary">Save theme</button>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    // Live preview: data-var pickers → CSS custom property on :root
    document.querySelectorAll('input[type="color"][data-var]').forEach(picker => {
        picker.addEventListener('input', () => {
            document.documentElement.style.setProperty(picker.dataset.var, picker.value);
        });
    });

    // Link colour: convert hex → rgb for live preview
    const linkPicker = document.querySelector('[data-live="link"]');
    if (linkPicker) {
        linkPicker.addEventListener('input', () => {
            const hex = linkPicker.value.replace('#', '');
            const r = parseInt(hex.slice(0,2), 16);
            const g = parseInt(hex.slice(2,4), 16);
            const b = parseInt(hex.slice(4,6), 16);
            document.documentElement.style.setProperty('--bs-link-color-rgb', r + ', ' + g + ', ' + b);
        });
    }

    // Form control component overrides — inject/update a live <style> block
    const formControlStyle = document.createElement('style');
    formControlStyle.id = 'formControlPreview';
    document.head.appendChild(formControlStyle);
    function updateFormControlPreview() {
        const color = document.getElementById('f_form_control_color')?.value || '';
        const bg    = document.getElementById('f_form_control_bg')?.value || '';
        formControlStyle.textContent =
            '.form-control, .form-select, .form-check-input {' +
            (color ? ' color: ' + color + ';' : '') +
            (bg    ? ' background-color: ' + bg + ';' : '') +
            ' }';
    }
    document.getElementById('f_form_control_color')?.addEventListener('input', updateFormControlPreview);
    document.getElementById('f_form_control_bg')?.addEventListener('input', updateFormControlPreview);
    updateFormControlPreview();

    // Stripe A & B: picker ↔ text field sync
    [['f_stripe_a_picker', 'f_stripe_a', '--row-stripe-a'],
     ['f_stripe_b_picker', 'f_stripe_b', '--row-stripe-b']].forEach(([pickerId, textId, prop]) => {
        const picker = document.getElementById(pickerId);
        const text   = document.getElementById(textId);
        if (!picker || !text) return;
        picker.addEventListener('input', () => {
            text.value = picker.value;
            document.documentElement.style.setProperty(prop, picker.value);
        });
        text.addEventListener('input', () => {
            document.documentElement.style.setProperty(prop, text.value);
            if (/^#[0-9a-fA-F]{6}$/.test(text.value.trim())) {
                picker.value = text.value.trim();
            }
        });
    });
})();
</script>
</body>
</html>
