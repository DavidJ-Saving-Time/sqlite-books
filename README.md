# SQLite Books

This project is a small PHP application for browsing and editing an eBook database. It now includes a PHP-based function for getting book recommendations from the OpenRouter API. Recommendations returned from the API are saved to the Calibre database in the `books_custom_column_10` table so they can be referenced later.

## Searching

Use the search bar at the top of `list_books.php` to search for books by title or author name. Results are displayed using the same table view as the regular book list.

To enable the recommendation feature, set the `OPENROUTER_API_KEY` environment variable with your API key. The `recommend.php` endpoint calls `get_book_recommendations()` defined in `book_recommend.php` to contact the API and return results.
