# seo — "Get found"

Search-engine and AI-answer basics for every site. Free, on by default; each piece can be turned off at
**Admin → Settings → Search & AI** (`/admin/settings/search`).

| Piece | Setting (site scope) | What it does |
|---|---|---|
| Master switch | `seo.enabled` | Off = the module adds nothing; robots.txt and sitemap.xml are the framework's again |
| Main address | `seo.main_host_redirect`, `seo.main_host` | `www.` (and, on the hosted Builder, the builder subdomain once a custom domain is verified) 301 to the main host. Defaults to APP_URL's host |
| HTTPS | `seo.https_redirect` | `http://` → `https://` when APP_URL says the main host has https |
| Canonical | `seo.canonical` | Adds `<link rel="canonical">` (no query string) to every 200 HTML page lacking one; corrects one that names this site under another of its hosts |
| Account pages | `seo.noindex_account` | `noindex, follow` on /login, /register, /password…, /session, /cookie-consent, /admin…; left out of the sitemap |
| robots.txt | `seo.robots_extras`, `seo.ai.search/input/train` | `Content-Signal: search=yes, ai-input=yes, ai-train=no` (default) + background paths disallowed |
| Structured data | `seo.jsonld`, `seo.business.*` | schema.org JSON-LD on the homepage, by business type |
| llms.txt | `seo.llms`, `seo.llms.intro` | `/llms.txt` + the homepage's `Link: </llms.txt>; rel="describedby"` |
| IndexNow | `seo.indexnow` | `/indexnow-key.txt` + `php artisan seo:indexnow` (scheduled daily 07:15 UTC); what was sent is kept in `seo.indexnow.sent` |
| Verification | `seo.verify.*` | Google, Bing, Yandex, Pinterest, Facebook meta tags on the homepage |
| Hide the site | `seo.hide_site` | noindex everywhere, empty sitemap, no llms.txt, no IndexNow |

On the hosted ClaudePHPBuilder, demos and free preview (proofing) sites are hidden from search automatically; an
operator can turn search on for a demo from the tenant's admin page.

Nothing pings a search engine from a local or test host (`.test`, `.local`, `localhost`, an IP) or over plain http.
