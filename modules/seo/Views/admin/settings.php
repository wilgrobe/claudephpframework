<?php
/**
 * /admin/settings/search — "Search & AI" (seo module).
 *
 * @var array<string,string>                 $values  every seo.* setting, defaults applied
 * @var \Modules\Seo\Services\SiteProfile    $profile
 * @var string                               $last    the last IndexNow run ("date status count http")
 * @var array<string,string>                 $types   schema.org business types
 */
use Modules\Seo\Services\SearchSettings;

$pageTitle = 'Search & AI';
$activePanel = 'search';
$f = static fn (string $key) => str_replace('.', '_', $key);
$v = static fn (string $key) => (string) ($values[$key] ?? '');
include BASE_PATH . '/app/Views/layout/header.php';
include BASE_PATH . '/modules/settings/Views/admin/_nav.php';
$hiddenWhy = [
    'staging' => 'This is a staging or preview copy of the site (SEARCH_HIDDEN=1 in its .env), so it stays out of search. The live site is not affected.',
    'demo'    => 'This is a demo site. Demos are hidden from search unless the Builder operator turns search on for this demo.',
    'preview' => 'This is a free preview (proofing) site. Previews are hidden from search; your site becomes findable when it is promoted to production.',
    'owner'   => 'You chose to hide this whole site from search (below).',
][$profile->hidden] ?? '';
?>
<style>
.seo-box { padding:.85rem 1rem; background:var(--accent-subtle); border:1px solid var(--border-strong); border-radius:6px; margin-bottom:1rem; }
.seo-row { display:flex; align-items:center; gap:.6rem; margin:.45rem 0; font-size:14px; }
.seo-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem; }
.seo-grid label, .seo-field label { display:block; font-size:12px; font-weight:600; color:var(--text-muted); margin-bottom:.2rem; }
.seo-grid input, .seo-grid select, .seo-field input, .seo-field textarea, .seo-field select { width:100%; box-sizing:border-box; }
.seo-muted { color:var(--text-muted); font-size:12.5px; line-height:1.5; margin:.2rem 0 .6rem; }
.seo-card h2 { font-size:1rem; margin:0 0 .35rem; }
.seo-radio { display:inline-flex; gap:.35rem; align-items:center; margin-right:1rem; font-size:14px; }
</style>

<h1 style="margin:0 0 .35rem;font-size:1.4rem">Search &amp; AI</h1>
<p class="seo-muted" style="font-size:13.5px">How search engines and AI assistants find and describe this site. Everything here is free and on by default; turn off anything you don't want.</p>

<?php if ($m = \Core\Session::flash('success')): ?><div class="alert alert-success"><?= e($m) ?></div><?php endif; ?>
<?php if ($m = \Core\Session::flash('error')): ?><div class="alert alert-danger"><?= e($m) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1rem"><div class="card-body">
    <div class="seo-row"><strong>Main address:</strong> <code><?= e($profile->base() ?: '—') ?></code></div>
    <?php if ($hiddenWhy !== ''): ?>
        <div class="seo-row" style="color:var(--color-warning-fg, #92400e)"><strong>Hidden from search.</strong> <?= e($hiddenWhy) ?></div>
    <?php else: ?>
        <div class="seo-row">Search engines may list this site.
            <a href="/robots.txt" target="_blank" rel="noopener">robots.txt</a> ·
            <a href="/sitemap.xml" target="_blank" rel="noopener">sitemap.xml</a> ·
            <a href="/llms.txt" target="_blank" rel="noopener">llms.txt</a></div>
    <?php endif; ?>
    <?php if ($last !== ''): ?><div class="seo-row seo-muted">Last IndexNow run: <?= e($last) ?></div><?php endif; ?>
</div></div>

<form method="POST" action="/admin/settings/search">
<?= csrf_field() ?>

<div class="card seo-card" style="margin-bottom:1rem"><div class="card-body">
    <h2>What's on</h2>
    <div class="seo-box">
        <label class="seo-row" style="font-weight:600;margin:0">
            <?= toggle_switch($f(SearchSettings::ENABLED), $v(SearchSettings::ENABLED) !== '0') ?>
            Get found (all of the below)
        </label>
        <div class="seo-muted" style="margin:.3rem 0 0">Off = this site adds nothing; robots.txt and sitemap.xml go back to the plain framework ones.</div>
    </div>
    <?php foreach (SearchSettings::SWITCHES as $key => $label): ?>
        <label class="seo-row"><?= toggle_switch($f($key), $v($key) !== '0') ?> <?= e($label) ?></label>
    <?php endforeach; ?>
</div></div>

<div class="card seo-card" style="margin-bottom:1rem"><div class="card-body">
    <h2>AI policy</h2>
    <p class="seo-muted">Written into robots.txt as a Content-Signal line. It states your terms; well-behaved AI crawlers follow it.</p>
    <?php foreach (['seo.ai.search' => 'Show this site in search results', 'seo.ai.input' => 'Let AI assistants use it to answer questions (with a link)', 'seo.ai.train' => 'Let AI companies train models on it'] as $key => $label): ?>
        <div class="seo-row" style="justify-content:space-between;max-width:620px">
            <span><?= e($label) ?></span>
            <span>
                <label class="seo-radio"><input type="radio" name="<?= e($f($key)) ?>" value="yes" <?= $v($key) !== 'no' ? 'checked' : '' ?>> Yes</label>
                <label class="seo-radio"><input type="radio" name="<?= e($f($key)) ?>" value="no" <?= $v($key) === 'no' ? 'checked' : '' ?>> No</label>
            </span>
        </div>
    <?php endforeach; ?>
</div></div>

<div class="card seo-card" style="margin-bottom:1rem"><div class="card-body">
    <h2>Main address</h2>
    <div class="seo-field" style="max-width:420px">
        <label for="seo_main_host">Main host name (leave empty to use the site's own domain)</label>
        <input type="text" id="seo_main_host" name="seo_main_host" class="form-control" value="<?= e($v('seo.main_host')) ?>" placeholder="example.com">
    </div>
    <p class="seo-muted">Other addresses of this site (www., the builder subdomain) redirect here permanently, and every page names this address as its canonical one.</p>
    <label class="seo-row"><?= toggle_switch('seo_hide_site', $v('seo.hide_site') === '1') ?> Hide this whole site from search engines</label>
</div></div>

<div class="card seo-card" style="margin-bottom:1rem"><div class="card-body">
    <h2>Search engine verification</h2>
    <p class="seo-muted">Paste the code (or the whole &lt;meta&gt; tag) each service gives you. It goes on the home page. Add the site to both Google Search Console and Bing Webmaster Tools: ChatGPT's search leans heavily on Bing, so Bing is how many AI answers find you.</p>
    <div class="seo-grid">
        <?php foreach (SearchSettings::VERIFY as $key => [$meta, $label]): ?>
            <div><label for="<?= e($f($key)) ?>"><?= e($label) ?></label>
            <input type="text" id="<?= e($f($key)) ?>" name="<?= e($f($key)) ?>" class="form-control" value="<?= e($v($key)) ?>"></div>
        <?php endforeach; ?>
    </div>
</div></div>

<div class="card seo-card" style="margin-bottom:1rem"><div class="card-body">
    <h2>About the business</h2>
    <p class="seo-muted">Used for the structured data on the home page and for /llms.txt. Empty fields fall back to the site name and tagline. Only fill in what is true and shown on your site.</p>
    <div class="seo-grid">
        <div><label for="seo_business_type">Kind of business</label>
            <select id="seo_business_type" name="seo_business_type" class="form-control">
                <?php foreach ($types as $t => $label): ?>
                    <option value="<?= e($t) ?>" <?= $v('seo.business.type') === $t ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select></div>
        <?php foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'street' => 'Street address', 'city' => 'City', 'region' => 'State / region', 'postal' => 'Postal code', 'country' => 'Country'] as $k => $label): ?>
            <div><label for="seo_business_<?= $k ?>"><?= e($label) ?></label>
            <input type="text" id="seo_business_<?= $k ?>" name="seo_business_<?= $k ?>" class="form-control" value="<?= e($v('seo.business.' . $k)) ?>"></div>
        <?php endforeach; ?>
    </div>
    <div class="seo-field" style="margin-top:.75rem"><label for="seo_business_description">One-sentence description</label>
        <input type="text" id="seo_business_description" name="seo_business_description" class="form-control" value="<?= e($v('seo.business.description')) ?>"></div>
    <div class="seo-field" style="margin-top:.75rem"><label for="seo_business_same_as">Profiles elsewhere (https:// links, one per line)</label>
        <textarea id="seo_business_same_as" name="seo_business_same_as" class="form-control" rows="3"><?= e($v('seo.business.same_as')) ?></textarea></div>
    <div class="seo-field" style="margin-top:.75rem"><label for="seo_llms_intro">For AI assistants: a short paragraph about the site (llms.txt)</label>
        <textarea id="seo_llms_intro" name="seo_llms_intro" class="form-control" rows="4"><?= e($v('seo.llms.intro')) ?></textarea></div>
</div></div>

<button type="submit" class="btn btn-primary">Save</button>
</form>

<?php $pages ??= null; ?>
<div class="card seo-card" id="pages" style="margin-top:1rem"><div class="card-body">
    <h2>Pages</h2>
    <?php if ($pages === null): ?>
        <p class="seo-muted">Per-page choices need this site's database to be brought up to date (php artisan migrate).</p>
    <?php elseif ($pages === []): ?>
        <p class="seo-muted">No published pages yet.</p>
    <?php else: ?>
        <p class="seo-muted">Hide a page from search engines and AI assistants (it stays on your site for anyone with the link), or name its canonical address: another page here ("/original-post") or the original elsewhere ("https://…"). Leave the address empty to use the page's own.</p>
        <form method="POST" action="/admin/settings/search/pages" style="margin:0">
            <?= csrf_field() ?>
            <table class="table" style="width:100%;font-size:14px">
                <thead><tr><th style="text-align:left">Page</th><th style="text-align:left">Hide from search</th><th style="text-align:left">Canonical address</th></tr></thead>
                <tbody>
                <?php foreach ($pages as $pg): $id = (int) $pg['id']; ?>
                    <tr>
                        <td><?= e((string) $pg['title']) ?><div class="seo-muted" style="margin:0">/<?= e((string) $pg['slug']) ?></div></td>
                        <td><input type="hidden" name="page_ids[]" value="<?= $id ?>">
                            <label class="seo-row" style="margin:0"><input type="checkbox" name="hidden[<?= $id ?>]" value="1" <?= (int) $pg['search_hidden'] === 1 ? 'checked' : '' ?>> Hidden</label></td>
                        <td><input type="text" name="canonical[<?= $id ?>]" class="form-control" maxlength="1000" value="<?= e((string) ($pg['canonical_url'] ?? '')) ?>" placeholder="(this page)"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary">Save pages</button>
        </form>
    <?php endif; ?>
</div></div>

<?php $texts ??= []; ?>
<div class="card seo-card" id="text" style="margin-top:1rem"><div class="card-body">
    <h2>Titles and descriptions</h2>
    <p class="seo-muted">What search results show for a page. Pages you made in the page editor have these on the page itself; use this for the others, such as /faq, /blog or /contact. Leave a part empty to keep what the page has now; empty both to remove the row. About 60 characters fit for a title and 160 for a description. The title is shown exactly as you type it.</p>
    <form method="POST" action="/admin/settings/search/text" style="margin:0">
        <?= csrf_field() ?>
        <table class="table" style="width:100%;font-size:14px">
            <thead><tr><th style="text-align:left;width:24%">Address</th><th style="text-align:left;width:32%">Title</th><th style="text-align:left">Description</th></tr></thead>
            <tbody>
            <?php
            $textRows = [];
            foreach ($texts as $tPath => $t) { $textRows[] = [$tPath, $t['title'], $t['description']]; }
            for ($i = 0; $i < 3; $i++) { $textRows[] = ['', '', '']; }
            foreach ($textRows as [$tPath, $tTitle, $tDesc]): ?>
                <tr>
                    <td><input type="text" name="text_path[]" class="form-control" maxlength="300" value="<?= e((string) $tPath) ?>" placeholder="/faq" aria-label="Address"></td>
                    <td><input type="text" name="text_title[]" class="form-control" maxlength="120" value="<?= e((string) $tTitle) ?>" placeholder="(as the page has it)" aria-label="Title"></td>
                    <td><textarea name="text_description[]" class="form-control" rows="2" maxlength="320" placeholder="(as the page has it)" aria-label="Description"><?= e((string) $tDesc) ?></textarea></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn btn-primary">Save titles and descriptions</button>
    </form>
</div></div>

<?php $report ??= null; ?>
<div class="card seo-card" id="health" style="margin-top:1rem"><div class="card-body">
    <h2>Search health</h2>
    <p class="seo-muted">Checks the live site the way a search engine and an AI crawler see it: https, one main address, robots.txt, the sitemap, the home page's tags, and whether AI assistants can read it.</p>
    <?php if ($report && $report['checks']): ?>
        <p class="seo-muted">Checked <strong><?= e($report['host']) ?></strong>:
            <?= count(array_filter($report['checks'], fn ($c) => $c['ok'] === true)) ?> passed,
            <?= count(array_filter($report['checks'], fn ($c) => $c['ok'] === false)) ?> to fix.</p>
        <ul style="list-style:none;padding:0;margin:0 0 .75rem">
            <?php foreach ($report['checks'] as $c): ?>
                <li style="padding:.45rem 0;border-bottom:1px solid var(--border-default)">
                    <span aria-hidden="true"><?= $c['ok'] === true ? '✅' : ($c['ok'] === false ? '❌' : '➖') ?></span>
                    <strong style="font-weight:500"><?= e($c['label']) ?></strong>
                    <span class="seo-muted"> — <?= e($c['detail']) ?></span>
                    <?php if ($c['fix'] !== ''): ?><div style="font-size:12.5px;margin:.2rem 0 0 1.6rem">How to fix: <?= e($c['fix']) ?></div><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="POST" action="/admin/settings/search/check" style="margin:0">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-secondary" data-busy="Checking…"><?= $report ? 'Check again' : 'Check the site now' ?></button>
    </form>
</div></div>

</main></div>

<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
