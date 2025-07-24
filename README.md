# SQLite Books

This project is a small PHP application for browsing and editing an eBook database. It now includes a PHP-based function for getting book recommendations from the OpenRouter API. Recommendations returned from the API are saved to the Calibre database in the `books_custom_column_10` table so they can be referenced later. If this table is missing from your database it will be created automatically the first time a recommendation is saved.

Each book also has a "Shelf" value stored in the `books_custom_column_11` table. Available shelf names are kept in a `shelves` table and displayed in the sidebar. You can add or remove shelves from that sidebar and click a shelf name to filter the list. Each book row includes a drop-down to select one of the shelves. All books default to `Ebook Calibre` if no explicit value is set.

Genres are stored in `custom_column_2` and listed in the sidebar. You can add,
rename or delete genres from that list just like shelves and status values.

## Searching

Use the search bar at the top of `list_books.php` to search for books by title or author name. A dropdown next to the search field lets you choose between searching the **local** Calibre database, querying the **Open Library** API, or searching **Anna's Archive**. Results from external sources are shown in the same table layout but without local-only actions.

When viewing a book you can click **Get Book Recommendations**. The returned text is parsed to identify
the recommended title and author. Each title links back to `list_books.php` with an Open Library search
so you can quickly explore more details about that book.

To enable the recommendation feature, set the `OPENROUTER_API_KEY` environment variable with your API key. The `recommend.php` endpoint calls `get_book_recommendations()` defined in `book_recommend.php` to contact the API and return results.
To enable searching Anna's Archive, set the `ANNA_API_KEY` environment variable. The search dropdown will query the Anna's Archive API when this option is selected. Search results from Anna's Archive now include a **Download** button showing the file type. Clicking it calls `annas_download.php`, which uses that API to fetch a direct download link and opens the file in a new tab. Results also display the cover image, genre, publication year and file size when provided by the API.
