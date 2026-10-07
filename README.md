# OSINT Framework (PHP + SQLite + Bootstrap)

A self-hosted, drop-in OSINT (Open Source Intelligence) tool directory inspired by [osintframework.com](https://osintframework.com).

Built with **PHP + SQLite + Bootstrap 5 + HTML/CSS/JS** — no Composer, no Node, no database server required.

---

## ✨ Features

### Core
- **277 pre-loaded OSINT tools** across **20 main categories** + **50+ subcategories** (Username, Email, Domain, IP, Phone, People, Image, Social Media, Crypto, Breaches, Network, Maps, etc.)
- **Hybrid layout**: collapsible category tree sidebar + card grid view (toggle between Hybrid / Grid / Tree)
- **Dark + Light theme** toggle with localStorage persistence (defaults to cyber-dark)
- **Live search** by name, description, or tags (returns JSON via `/api/search.php`)
- **Responsive design**: works on mobile, tablet, desktop
- **Accessibility**: ARIA labels, skip-to-content link, focus-visible outlines

### User features
- **Tool metadata**: cost (free/freemium/paid), access (web/api/software/extension), auth-required flag
- **User accounts**: register / login / logout / favorites (bookmarks)
- **Rating system**: 1-5 stars + written reviews per tool
- **Favicon scraper** with local caching (1 week TTL)

### Admin features
- **Admin panel**: full CRUD for categories and tools
- **Dashboard**: stats overview, recently added tools, top-rated tools
- **DB utilities**: clear favicon cache, recompute ratings, export JSON backup, reset DB to defaults
- **API documentation page**: in-browser reference for all endpoints

### Developer features
- **JSON API**: programmatic access for search, listings, ratings, favorites
- **Docker support**: one-command deploy via `docker compose up -d`
- **XAMPP-ready**: drop into htdocs and run setup
- **Zero external dependencies** beyond CDN-loaded Bootstrap + Font Awesome

---

## 📂 Project Structure

```
osint-framework/
├── index.php              # Main page (hybrid view: tree sidebar + grid)
├── tool.php               # Tool detail page (with reviews + related)
├── api-docs.php           # In-browser API documentation
├── setup.php              # DB initialization (creates + seeds DB)
├── config.php             # All configuration (admin creds, paths)
├── .htaccess              # Apache security rules
├── .gitignore
├── Dockerfile             # PHP 8.2 + Apache + SQLite image
├── docker-compose.yml     # One-command deploy
├── docker-quickstart.md   # Docker how-to
├── README.md              # This file
│
├── api/                   # JSON API endpoints
│   ├── search.php         # GET /api/search.php?q=...
│   ├── tools.php          # GET /api/tools.php?cat=...
│   ├── favorite.php       # POST: toggle favorite (requires login)
│   ├── rate.php           # POST: submit rating (requires login)
│   └── favicon.php        # GET: cached favicon proxy
│
├── admin/                 # Admin panel
│   ├── login.php          # Admin login (default: admin/admin123)
│   ├── dashboard.php      # Stats + quick links
│   ├── categories.php     # Category CRUD
│   ├── tools.php          # Tool CRUD with filter
│   └── db-utils.php       # DB maintenance utilities
│
├── auth/                  # User authentication
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   └── favorites.php      # User's bookmarked tools
│
├── includes/              # Shared components
│   ├── db.php             # PDO + SQLite connection
│   ├── functions.php      # All helper functions
│   ├── header.php         # Top nav + theme toggle
│   └── footer.php         # Footer + scripts
│
├── assets/
│   ├── css/style.css      # Light + dark theme CSS
│   ├── js/theme.js        # Theme toggle (localStorage + cookie)
│   ├── js/app.js          # Tree, search, favorites, ratings JS
│   └── img/default-favicon.svg
│
└── data/                  # SQLite DB + favicon cache (writable)
    ├── osint.db           # Created by setup.php
    ├── favicons/          # Cached PNG favicons
    └── index.php          # 403 block (security)
```

---

## 🚀 Installation

### Option A: XAMPP (recommended for beginners)

1. **Copy the folder** to your XAMPP htdocs:
   ```
   C:\xampp\htdocs\osint-framework\
   ```
   On macOS XAMPP: `/Applications/XAMPP/htdocs/osint-framework/`
   On Linux LAMP: `/var/www/html/osint-framework/`

2. **Make the data folder writable** (Apache needs write access):
   ```bash
   chmod -R 775 data/
   chown -R www-data:www-data data/   # Linux only
   ```

3. **Initialize the database** — open browser to:
   ```
   http://localhost/osint-framework/setup.php
   ```
   This creates `data/osint.db`, creates all 5 tables, seeds ~277 OSINT tools across 20+ categories, and creates the default admin user.

4. **Visit the app**:
   ```
   http://localhost/osint-framework/index.php
   ```

### Option B: Docker

```bash
cd osint-framework
docker compose up -d --build
```

Then visit:
1. http://localhost:8080/setup.php  (initialize the database — only needed once)
2. http://localhost:8080/

The `data/` directory is persisted as a Docker named volume (`osint-data`) so the database survives container rebuilds. See `docker-quickstart.md` for details.

### Option C: Any PHP-capable server

Requirements:
- PHP 7.4+ (8.x recommended)
- SQLite 3 with PDO extension (usually enabled by default)
- Apache with `mod_rewrite` (optional but recommended for `.htaccess`)

Just copy the files to your web root and visit `setup.php`.

---

## 🔐 Admin Access

- **URL**: `http://localhost/osint-framework/admin/login.php`
- **Default credentials** (CHANGE in `config.php`):
  - Username: `admin`
  - Password: `admin123`

In production, edit `config.php`:
```php
define('ADMIN_USER', 'your-username');
define('ADMIN_PASS', 'your-strong-password');
```

The admin panel provides:
- Dashboard with stats
- Add/edit/delete categories (with parent-child hierarchy)
- Add/edit/delete tools (with cost, access type, tags, auth-required flag)
- Database utilities (clear favicon cache, recompute ratings, export JSON, reset DB)

---

## 📡 JSON API

Full documentation at: **`http://localhost/osint-framework/api-docs.php`**

### Quick reference

#### Search tools
```
GET /api/search.php?q=shodan
```

#### List tools in a category
```
GET /api/tools.php?cat=ip-geo
GET /api/tools.php?cat=ip-geo&cost=freemium
GET /api/tools.php?cat=ip-geo&access=api
```

#### Submit a rating (requires login session)
```
POST /api/rate.php
Content-Type: application/json

{"tool_id": 42, "rating": 5, "review": "Excellent tool"}
```

#### Toggle favorite (requires login session)
```
POST /api/favorite.php
Content-Type: application/json

{"tool_id": 42}
```

#### Fetch favicon (cached, returns PNG)
```
GET /api/favicon.php?id=42
```

### Authentication flow
```
# Step 1: Login (sets session cookie)
curl -c cookies.txt -X POST \
  -d "identifier=admin&password=admin123" \
  http://localhost/osint-framework/auth/login.php

# Step 2: Call protected endpoint with cookie
curl -b cookies.txt \
  -X POST -H "Content-Type: application/json" \
  -d '{"tool_id":42}' \
  http://localhost/osint-framework/api/favorite.php
```

---

## 🎨 Theme Customization

Click the sun/moon icon in the navbar to toggle between **light** and **dark** themes. The selection is saved in:
- `localStorage` for instant client-side persistence
- A cookie (`theme=dark|light`) for server-side rendering on first paint

To change the default theme, edit `config.php`:
```php
define('DEFAULT_THEME', 'light'); // or 'dark'
```

To customize colors, edit CSS variables at the top of `assets/css/style.css`:
```css
:root { --accent: #2563eb; ... }
[data-theme="dark"] { --accent: #00d9ff; ... }
```

---

## 🛠 Customization

### Add a new tool
1. Login as admin → **Admin → Add Tool** (or click "Add Tool" on dashboard)
2. Fill name, URL, category, cost type, access type, tags
3. Favicon is auto-fetched from the domain via Google's s2 service

### Add a new category
1. Login as admin → **Admin → Add Category**
2. Choose parent (or leave blank for root), name, slug, Bootstrap icon name
3. Icon names: https://icons.getbootstrap.com/ (e.g. `person`, `globe`, `envelope`)

### Change site name
Edit `config.php` → `SITE_NAME`

### Migrate to MySQL
Only `includes/db.php` needs editing. Replace the SQLite PDO with MySQL PDO:
```php
$pdo = new PDO('mysql:host=localhost;dbname=osint', 'user', 'pass');
```
All SQL uses standard ANSI syntax compatible with both SQLite and MySQL.

---

## 🔧 Tech Notes

- **PHP 7.4+** required (8.x recommended)
- **SQLite 3** via PHP PDO (no external DB needed)
- **Bootstrap 5.3** + **Bootstrap Icons 1.11** + **Font Awesome 6.5** from CDN
- **No Composer dependencies**, **no Node build step**
- All AJAX endpoints return JSON with proper HTTP status codes
- Foreign key cascades enabled (`PRAGMA foreign_keys = ON`)
- Password hashing via `password_hash()` (bcrypt)
- Session-based authentication (cookie + server-side)

---

## 🗃 Database Schema

5 tables with proper foreign keys and cascade deletes:

```sql
categories (id, parent_id, name, slug, icon, description, sort_order, created_at)
tools (id, category_id, name, url, description, cost_type, access_type,
       requires_auth, tags, favicon, rating_avg, rating_count,
       created_at, updated_at)
users (id, username, email, password_hash, role, created_at)
ratings (id, user_id, tool_id, rating [1-5], review, created_at, UNIQUE(user_id, tool_id))
favorites (id, user_id, tool_id, created_at, UNIQUE(user_id, tool_id))
```

---

## 📝 License & Disclaimer

This is a directory of publicly-available OSINT tools — it does not host any of the linked tools. Each tool retains its own license. Use responsibly and only for legal, ethical investigations in your jurisdiction.

The categories and tool listings are inspired by [osintframework.com](https://osintframework.com) but implemented independently. All seed data was compiled from publicly available sources.

---

## 🤝 Credits

- Original OSINT Framework concept: [osintframework.com](https://osintframework.com)
- Favicon service: Google s2 (`https://www.google.com/s2/favicons`)
- Icons: Bootstrap Icons + Font Awesome
- Frontend: Bootstrap 5.3
- Database: SQLite 3
- Fonts: System fonts (Inter on dark theme)

---

## 📋 Changelog

### v1.0.0
- Initial release
- 277 OSINT tools pre-seeded across 20 main categories
- Hybrid tree+grid layout with theme toggle
- User accounts, favorites, ratings
- Admin CRUD for categories and tools
- JSON API with 5 endpoints
- Docker + XAMPP deployment options
- Full accessibility (ARIA, skip-link, focus-visible)
