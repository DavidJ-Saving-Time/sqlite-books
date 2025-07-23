import requests
import os
import sys

# Set your OpenRouter API key (or set as an environment variable)
API_KEY = os.getenv("OPENROUTER_API_KEY", "your_api_key_here")

def get_book_recommendations(user_preferences):
    """
    Generate book recommendations using Claude 3.5 Sonnet via OpenRouter.
    :param user_preferences: A string describing the user's taste in books.
    :return: A string with book recommendations.
    """
    url = "https://openrouter.ai/api/v1/chat/completions"

    headers = {
        "Authorization": f"Bearer {API_KEY}",
        "Content-Type": "application/json",
    }

    payload = {
        "model": "anthropic/claude-3.5-sonnet",
        "messages": [
            {
                "role": "system",
                "content": "You are a knowledgeable book recommendation assistant. Suggest books tailored to the user's taste with a short explanation for each recommendation."
            },
            {
                "role": "user",
                "content": f"I enjoy {user_preferences}. Can you recommend 5 similar books with a one-sentence reason for each?"
            }
        ],
        "temperature": 0.7,
        "max_tokens": 500
    }

    response = requests.post(url, headers=headers, json=payload)
    response.raise_for_status()

    data = response.json()
    return data["choices"][0]["message"]["content"].strip()

if __name__ == "__main__":
    # Accept the author name and book title from the command line
    if len(sys.argv) >= 3:
        user_input = f"{sys.argv[1]} {sys.argv[2]}"
    elif len(sys.argv) == 2:
        user_input = sys.argv[1]
    else:
        user_input = "fantasy novels like 'The Name of the Wind' and 'Mistborn'"

    recommendations = get_book_recommendations(user_input)
    print("\nBook Recommendations:\n")
    print(recommendations)

