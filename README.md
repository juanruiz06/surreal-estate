# 🏠 Surreal Estate: AI Driven Property Analytics 

**Surreal Estate** is a high-performance Real Estate MVP built for the Madrid market. Moving away from the traditional "static filter" paradigm, it offers a personalized, consultant-like experience using AI to match users with properties that fit their actual lifestyle, preferences and financial reality.

🔗 **[Watch the 3-minute Video Demo Here]** *(Insert your Loom link here!)*

---

## ✨ Key AI Features

* **The Lifestyle Consultant:** An interactive AI advisor that asks about your life (commute, savings, hobbies) and recommends properties. It enforces real-world financial guardrails (e.g., the 30% entry rule in Spain) and is able to interpret free form text as well.
* **The Surreal Score:** An automated AI valuation engine that scores properties from 1.0 to 10.0 based on price-per-m2 against neighborhood averages, ammenities, it-factor... It provides brutally honest insights, to get the point across and gather the user's attention.
* **Smart Search:** A natural language processing search bar. Just type *"I want a bright penthouse near Retiro for under 1M"* and let the AI do the filtering.

---

## 🛠️ Tech Stack

* **Backend:** Laravel 11 (PHP 8.3)
* **Frontend:** Flux UI (Powered by Laravel Livewire & Tailwind CSS)
* **AI Engine:** OpenAI API (GPT-4o-mini)
* **Infrastructure:** Docker (Laravel Sail) + MySQL

---

## 🚀 Getting Started (Running Locally)

This project is fully dockerized using Laravel Sail, ensuring a seamless setup process without needing PHP or MySQL installed on your local machine.

### 1. Prerequisites
* [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.
* Git.

### 2. Installation

Clone the repository and navigate into it:

```bash
git clone https://github.com/juanruiz06/surreal-estate.git
cd surreal-estate
```

Copy the environment file:

```bash
cp .env.example .env
```

Install Composer dependencies (using a small Docker container to ensure compatibility):

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php83-composer:latest \
    composer install --ignore-platform-reqs
```

Start the Docker containers:

```bash
./vendor/bin/sail up -d
```

Generate the application key:

```bash
./vendor/bin/sail artisan key:generate
```

### 3. Database Seeding & Pre-loaded Data

To make testing easier and avoid unnecessary API costs, the database is pre-seeded with 300 real properties from Madrid. Their Surreal Scores and AI-generated insights are already calculated and stored.

Run the migrations and the custom JSON seeder:

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

### 4. Compile Frontend Assets

```bash
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

The application is now accessible at 👉 http://localhost

## 🤖 Note on AI Features & OpenAI API Key

You can browse the 300 pre-seeded properties, view their details, and read the pre-calculated AI insights without an API key.

However, to test the real-time interactive features (Smart Search Assistant and Lifestyle Consultant), you must provide your own OpenAI API key.

Open your `.env` file.

Add your key:

```bash
OPENAI_API_KEY=sk-your-key-here
```

The features will instantly become active.

(Note: The app is configured to use `gpt-4o-mini` for optimal speed and cost-efficiency.)
