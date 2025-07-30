<!DOCTYPE html>
<script src="node_modules/tinymce/tinymce.min.js" referrerpolicy="origin"></script>
<script src="https://unpkg.com/turndown/dist/turndown.js"></script>
<style>
/* For other boilerplate styles, see: https://www.tiny.cloud/docs/tinymce/7/editor-content-css/ */
/*
* For rendering images inserted using the image plugin.
* Includes image captions using the HTML5 figure element.
*/

.tox-tinymce {
     border-radius: 0px !important;
}

figure.image {
  display: inline-block;
  border: 1px solid gray;
  margin: 0 2px 0 1px;
  background: #f5f2f0;
}

figure.align-left {
  float: left;
}

figure.align-right {
  float: right;
}

figure.image img {
  margin: 8px 8px 0 8px;
}

figure.image figcaption {
  margin: 6px 8px 6px 8px;
  text-align: center;
}

/*
 Alignment using classes rather than inline styles
 check out the "formats" option
*/

img.align-left {
  float: left;
}

img.align-right {
  float: right;
}

/* Basic styles for Table of Contents plugin (tableofcontents) */
.mce-toc {
  border: 1px solid gray;
}

.mce-toc h2 {
  margin: 4px;
}

.mce-toc li {
  list-style-type: none;
}
</style>
<script>

const useDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
const isSmallScreen = window.matchMedia('(max-width: 1023.5px)').matches;

	tinymce.init({
  selector: 'textarea#open-source-plugins',
  license_key: 'gpl',
		promotion: false,      // ← disable the upgrade promo
  branding: false,       // ← also hides the “Powered by Tiny” link
  plugins: 'preview importcss searchreplace autolink autosave directionality code visualblocks visualchars fullscreen image link media codesample table charmap pagebreak nonbreaking anchor insertdatetime advlist lists wordcount help charmap emoticons accordion',
  toolbar: 'undo redo | openLocal saveLocal | bold italic underline | fullscreen preview',
    setup: function(editor) {
      // 1) Create hidden file input
      const fileInput = document.createElement('input');
      fileInput.type    = 'file';
      fileInput.accept  = '.html,.htm';
      fileInput.style.display = 'none';
      document.body.appendChild(fileInput);

      fileInput.addEventListener('change', () => {
        const file = fileInput.files[0];
        if (file) {
          const reader = new FileReader();
          reader.onload = e => editor.setContent(e.target.result);
          reader.readAsText(file);
        }
        fileInput.value = '';
      });

      // 2) “Open…” button
      editor.ui.registry.addButton('openLocal', {
        text: 'Open',
        icon: 'browse',
        onAction: () => fileInput.click()
      });

      // 3) “Save…” button
      editor.ui.registry.addButton('saveLocal', {
        text: 'Save',
        icon: 'save',
        onAction: () => {
          const html = editor.getContent({ format: 'html' });
          const blob = new Blob([html], { type: 'text/html' });
          const url  = URL.createObjectURL(blob);
          const a    = document.createElement('a');
          a.href     = url;
          a.download = 'document.html';
          document.body.appendChild(a);
          a.click();
          setTimeout(() => {
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
          }, 100);
        }
      });
    // 6) (Optional) Auto-enter fullscreen on init
    editor.on('init', function () {
      editor.execCommand('mceFullScreen');
    });
  },
  skin: useDarkMode ? 'oxide-dark' : 'oxide',
  content_css: useDarkMode ? 'dark' : 'default',
  height: 600,
  content_style: `
    body.mce-content-body {
      max-width: 1200px;    /* your “page width” */
      margin: 0 auto;      /* center it */
      padding: 1em;        /* optional inner padding */
      box-sizing: border-box;
    }
  `,
});
</script>

<textarea id="open-source-plugins" style="max-width:1000px">
</textarea>



