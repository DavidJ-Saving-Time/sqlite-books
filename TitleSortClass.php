<?php
class TitleSort
{
    private $mode;
    private $articlesByLang = [
        'en' => ['a', 'an', 'the'],
        // add other languages if desired (e.g. 'de' => ['der','die','das'])
    ];

    public function __construct($mode = 'library_order')
    {
        // mode: library_order (default Calibre behavior) or strictly_alphabetic
        $this->mode = ($mode === 'strictly_alphabetic') ? 'strictly_alphabetic' : 'library_order';
    }

    /**
     * Compute the title_sort value.
     *
     * @param string $title Original title
     * @param string $lang ISO language code (default 'en')
     * @return string Sorted title
     */
    public function sort($title, $lang = 'en')
    {
        $title = trim($title);
        if ($this->mode === 'strictly_alphabetic' || empty($title)) {
            return $title;
        }

        $lang = strtolower($lang);
        $articles = $this->articlesByLang[$lang] ?? $this->articlesByLang['en'];

        $lower = mb_strtolower($title, 'UTF-8');
        foreach ($articles as $article) {
            $prefix = $article . ' ';
            if (mb_substr($lower, 0, mb_strlen($prefix)) === $prefix) {
                // Remove the first occurrence of the article
                $new = mb_substr($title, mb_strlen($prefix));
                return ltrim($new);
            }
        }

        return $title;
    }

    public function setMode($mode)
    {
        $this->mode = ($mode === 'strictly_alphabetic') ? 'strictly_alphabetic' : 'library_order';
    }
}

