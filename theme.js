(function(){
  const API_URL = 'https://bootswatch.com/api/5.json';
  const defaultTheme = {
    name: 'Bootstrap',
    css: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css'
  };

  const link = document.getElementById('themeStylesheet');
  const savedCss = localStorage.getItem('themeCss') || defaultTheme.css;
  if(link) link.href = savedCss;
  const select = document.getElementById('themeSelect');

  function populate(themes){
    if(!select) return;
    select.innerHTML = '';
    themes.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t.css;
      opt.textContent = t.name;
      select.appendChild(opt);
    });
    select.value = savedCss;
    select.addEventListener('change', () => {
      const css = select.value;
      if(link) link.href = css;
      localStorage.setItem('themeCss', css);
    });
  }

  fetch(API_URL)
    .then(r => r.json())
    .then(data => {
      const themes = data.themes.map(t => ({name: t.name, css: t.cssCdn}));
      populate([defaultTheme, ...themes]);
    })
    .catch(() => {
      populate([defaultTheme]);
    });
})();
