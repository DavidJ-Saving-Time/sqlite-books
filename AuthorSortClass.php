<?php

class AuthorSort
{
    private $method;

    // Known name particles (treated as part of last name)
    private $particles = ['da', 'de', 'del', 'della', 'di', 'du', 'la', 'le', 'van', 'von', 'der', 'den', 'ter', 'ten', 'el'];

    // Known suffixes
    private $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv'];

    public function __construct($method = 'invert')
    {
        $this->setMethod($method);
    }

    public function sort($authorString)
    {
        $authors = preg_split('/\s*(?:&| and )\s*/i', $authorString);
        $sortedAuthors = [];

        foreach ($authors as $author) {
            $sortedAuthors[] = $this->sortSingleAuthor(trim($author));
        }

        return implode(' & ', $sortedAuthors);
    }

    private function sortSingleAuthor($author)
    {
        switch ($this->method) {
            case 'copy':
                return $author;

            case 'comma':
                return (strpos($author, ',') !== false) ? $author : $this->invertName($author);

            case 'nocomma':
                return str_replace(',', '', $this->invertName($author));

            case 'invert':
            default:
                return $this->invertName($author);
        }
    }

    private function invertName($name)
    {
        // If already "Last, First" just return
        if (strpos($name, ',') !== false) {
            return $name;
        }

        $parts = preg_split('/\s+/', trim($name));
        $numParts = count($parts);

        if ($numParts <= 1) {
            return $name; // Single name, no inversion
        }

        // Check for suffix (last part is Jr, III, etc.)
        $suffix = '';
        $last = strtolower($parts[$numParts - 1]);
        if (in_array($last, $this->suffixes)) {
            $suffix = ' ' . array_pop($parts);
            $numParts--;
        }

        // Identify last name (with possible particles like van, de)
        $lastName = array_pop($parts);
        while ($numParts > 1 && in_array(strtolower($parts[$numParts - 2]), $this->particles)) {
            $lastName = array_pop($parts) . ' ' . $lastName;
            $numParts--;
        }

        $firstNames = implode(' ', $parts);
        return trim($lastName . $suffix . ', ' . $firstNames);
    }

    public function setMethod($method)
    {
        $method = strtolower($method);
        $this->method = in_array($method, ['invert', 'copy', 'comma', 'nocomma']) ? $method : 'invert';
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function setParticles(array $particles)
    {
        $this->particles = array_map('strtolower', $particles);
    }

    public function setSuffixes(array $suffixes)
    {
        $this->suffixes = array_map('strtolower', $suffixes);
    }
}

// --- Example usage ---
$sorter = new AuthorSort('invert');
echo $sorter->sort("Vincent van Gogh & John Smith Jr.") . "\n";
// Output: "van Gogh, Vincent & Smith Jr., John"

