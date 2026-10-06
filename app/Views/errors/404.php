<?php
// app/Views/errors/404.php — the "not found" page for controllers that render it themselves
// (Response::view('errors.404', [], 404) — policies, gdpr, ccpa, …). It was missing, so those
// not-found paths were a 500 instead of a 404. Kept byte-identical builder ↔ framework.
$pageTitle = 'Page not found';
include BASE_PATH . '/app/Views/layout/header.php';
?>
<div class="shell shell--medium">
  <div class="card">
    <div class="card-body" style="text-align:center;padding:3rem 1.5rem">
      <h1 style="margin:0 0 .5rem">Page not found</h1>
      <p style="color:var(--text-muted, #666);margin:0 0 1.5rem">The page you were looking for isn't here. It may have moved, or the link may be out of date.</p>
      <a class="btn btn-primary" href="/">Go to the home page</a>
    </div>
  </div>
</div>
<?php include BASE_PATH . '/app/Views/layout/footer.php'; ?>
