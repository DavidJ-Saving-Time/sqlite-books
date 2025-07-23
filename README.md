# SQLite Books

This project is a small PHP application for browsing and editing an eBook database. It now includes a PHP-based function for getting book recommendations from the OpenRouter API.

To enable the recommendation feature, set the `OPENROUTER_API_KEY` environment variable with your API key. The `recommend.php` endpoint calls `get_book_recommendations()` defined in `book_recommend.php` to contact the API and return results.
