<?php $pageTitle = 'Commerce Settings'; $activePanel = 'commerce'; ?>
<?php include BASE_PATH . '/app/Views/layout/header.php'; ?>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:0 0 1rem;font-size:1.4rem">Commerce</h1>
<p style="color:var(--color-gray-500);font-size:13.5px;margin:0 0 1.25rem;max-width:560px">
    Storefront defaults — feature toggles, currency, checkout behavior.
    Operational data (products, orders, shipping zones, tax rates) lives
    on its own admin pages reached from the
    <a href="/admin/store/products" style="color:var(--color-primary)">Store products</a>
    sidebar entry.
</p>

<div class="card">
    <form method="post" action="/admin/settings/commerce">
        <?= csrf_field() ?>
        <div class="card-body">

            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Reviews</h3>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;margin-bottom:.75rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('store.reviews_enabled', !empty($values['store.reviews_enabled']) && $values['store.reviews_enabled'] !== 'false') ?>
                    Show reviews on product pages
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Renders the aggregate stars bar, reviews list, and write-a-review
                    form at the bottom of each product page. Off hides the section
                    entirely without deleting reviews.
                </div>
            </div>

            <div class="form-group" style="padding:.85rem 1rem;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-weight:500;margin:0">
                    <?= toggle_switch('store.reviews_badge_in_listing', !empty($values['store.reviews_badge_in_listing']) && $values['store.reviews_badge_in_listing'] !== 'false') ?>
                    Show stars badges on product cards in /shop
                </label>
                <div style="font-size:12.5px;color:#4338ca;margin-top:.35rem;line-height:1.5">
                    Inline <code>★★★★½ 4.2 · 27 reviews</code> badge under each product.
                    Badges silently omit themselves when a product has zero reviews.
                </div>
            </div>

            <hr style="margin:1.5rem 0;border:0;border-top:1px solid var(--color-gray-200)">
            <h3 style="margin:0 0 .85rem;font-size:1.05rem">Currency &amp; checkout</h3>

            <div class="form-group">
                <label for="store.currency_default">Default currency (3-letter ISO 4217)</label>
                <input id="store.currency_default" name="store.currency_default" class="form-control" maxlength="3"
                       value="<?= e((string) ($values['store.currency_default'] ?? 'USD')) ?>" placeholder="USD">
                <small style="color:var(--color-gray-500)">Used when a product doesn't specify its own. Multi-currency stores set per-product.</small>
            </div>

            <div class="form-group">
                <label for="store.low_stock_threshold">Low-stock threshold</label>
                <input id="store.low_stock_threshold" name="store.low_stock_threshold" type="number" min="0" max="9999" class="form-control"
                       value="<?= e((string) ($values['store.low_stock_threshold'] ?? '5')) ?>">
                <small style="color:var(--color-gray-500)">When a stock-tracked product drops to this level, the listing shows "Only X left." 0 disables the warning.</small>
            </div>

        </div>
        <div class="card-body" style="background:var(--color-gray-50);border-top:1px solid var(--color-gray-200);display:flex;justify-content:flex-end">
            <button type="submit" class="btn btn-primary">Save Commerce</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-header"><h3 style="margin:0;font-size:.95rem">Operational pages</h3></div>
    <div class="card-body" style="display:grid;gap:.5rem;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr))">
        <a href="/admin/store/products" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Products</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Catalog CRUD + variants + images + specs.</div>
        </a>
        <a href="/admin/store/orders" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Orders</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Order list, status changes, fulfillment.</div>
        </a>
        <a href="/admin/store/shipping" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Shipping zones</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Per-region flat rates with free-over thresholds.</div>
        </a>
        <a href="/admin/store/tax" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Tax rates</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Per-(country, region, tax_class) rate table.</div>
        </a>
        <a href="/admin/coupons" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Coupons</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Discount codes + usage limits.</div>
        </a>
        <a href="/admin/subscription-plans" style="padding:.75rem;border:1px solid var(--color-gray-200);border-radius:6px;color:inherit;text-decoration:none">
            <strong>Subscription plans</strong>
            <div style="color:var(--color-gray-500);font-size:12.5px;margin-top:.2rem">Recurring-billing plan definitions.</div>
        </a>
    </div>
</div>

</main></div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
