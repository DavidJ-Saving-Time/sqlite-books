# SQLite Books

This project is a small PHP application for browsing and editing an eBook database. It now includes a PHP-based function for getting book recommendations from the OpenRouter API. Recommendations returned from the API are saved to the Calibre database in the custom column labeled `#recommendation`. If the underlying table for this column is missing it will be created automatically the first time a recommendation is saved.

When a different Calibre library is selected, the application ensures that all required custom tables (for genres, reading status, shelves and recommendations) are present in that database. Missing tables are created on the fly so the interface works with a fresh database without manual setup.

Each book also has a "Shelf" value stored in the custom column labeled `#shelf`. Available shelf names are kept in a `shelves` table and displayed in the sidebar. You can add or remove shelves from that sidebar and click a shelf name to filter the list. Each book row includes a drop-down to select one of the shelves. All books default to `Ebook Calibre` if no explicit value is set.

Genres are stored in the custom column labeled `genre` and listed in the sidebar. You can add,
rename or delete genres from that list just like shelves and status values.

## Preferences

Visit `login.php` to sign in. User accounts and their preferences are stored in
`users.json`. After logging in you can open `preferences.php` to set the path to
your Calibre `metadata.db` file. Preferences are saved per user without using
PHP sessions. You can still choose to save the path globally for all users which
updates the `preferences.json` file.

## Adding Your Own Books

Use `add_physical_book.php` to upload an eBook file directly through the web
interface. The page stores the file in the Calibre library structure and
inserts the metadata into the database, similar to Calibre's "Add Book" feature.

## Searching

Use the search bar at the top of `list_books.php` to search for books by title or author name. A dropdown next to the search field lets you choose between searching the **local** Calibre database, querying the **Open Library** API, searching **Google Books**, or searching **Anna's Archive**. Results from external sources are shown in the same table layout but without local-only actions.

When viewing a book you can click **Get Book Recommendations**. The returned text is parsed to identify
the recommended title and author. Each title links back to `list_books.php` with an Open Library search
so you can quickly explore more details about that book.

To enable the recommendation feature, set the `OPENROUTER_API_KEY` environment variable with your API key. The `recommend.php` endpoint calls `get_book_recommendations()` defined in `book_recommend.php` to contact the API and return results.
To enable searching Anna's Archive, set the `ANNA_API_KEY` environment variable. The search dropdown will query the Anna's Archive API when this option is selected. Search results from Anna's Archive now include a **Download** button showing the file type. Clicking it calls `annas_download.php`, which uses that API to fetch a direct download link and opens the file in a new tab. Results also display the cover image, genre, publication year and file size when provided by the API.
Set the `GOOGLE_BOOKS_API` environment variable to use the new **Metadata Google** button, which searches the Google Books API for updated information about a title. Results from Google now include a description when available, and selecting **Use This** will save that text to the book's entry.
