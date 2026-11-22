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
| Markdown for AI | `seo.markdown` | A public page requested with `Accept: text/markdown` (preferred over HTML) is answered as Markdown with its title, description and canonical address on top (`MarkdownPage`); such pages send `Vary: Accept`. Never on a hidden site or page, or on sign-in/account pages |
| IndexNow | `seo.indexnow` | `/indexnow-key.txt` + `php artisan seo:indexnow` (scheduled daily 07:15 UTC); what was sent is kept in `seo.indexnow.sent` |
| Verification | `seo.verify.*` | Google, Bing, Yandex, Pinterest, Facebook meta tags on the homepage |
| Hide the site | `seo.hide_site` | noindex everywhere, empty sitemap, no llms.txt, no IndexNow |
| AI crawlers by name | (follows `seo.ai.input` / `seo.ai.train`) | robots.txt adds named `User-agent` groups with `Disallow: /` for the AI training crawlers (and the AI-answer crawlers when answers are off) — they obey named groups, not the Content-Signal line |
| Search health | — | `POST /admin/settings/search/check`: the live site checked as a search engine and an AI crawler see it (https, main address, robots, sitemap, the home page's tags, AI crawler access), each failure with its fix |
| Per page | `pages.search_hidden`, `pages.canonical_url` (this module's migration) | A page hidden from search gets `noindex, follow` (header + tag) and leaves the sitemap, llms.txt and IndexNow; a canonical override (a path here or a full address elsewhere) replaces the page's own. Set under **Pages** on the same settings page |

On the hosted ClaudePHPBuilder, demos and free preview (proofing) sites are hidden from search automatically; an
operator can turn search on for a demo from the tenant's admin page.

A staging or preview copy hides itself when its `.env` has `SEARCH_HIDDEN=1`; the hosted Builder writes that on every managed staging server.

Nothing pings a search engine from a local or test host (`.test`, `.local`, `localhost`, an IP) or over plain http.

On the hosted ClaudePHPBuilder the wizard's step 8 "Get found" card sets the AI policy, business type and description, main address, Google/Bing verification and hide-the-site at build time. A republish only overwrites the ones the owner has not changed on the site (`SearchSeed`, remembered in `seo.wizard_seeded`); downloads take them on their first deploy only.

## Adding to llms.txt from a module

A module provider may declare `public function llmsSections(string $base): array` (optional — it is looked up with
`method_exists`, so the framework's `ModuleProvider` doesn't need it). Return `heading => list of items`, each item
`['title' => …, 'url' => '/path or a full URL on this site', 'about' => 'one line, optional']`. Addresses on other
sites, and sign-in/account pages, are dropped; a section left empty is not printed. StoriesDen's publish-hub uses it
for its comparison pages and storefront guides.
