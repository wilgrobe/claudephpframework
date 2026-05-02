<?php
// Smoke-test: BlockRegistry::render() admin-vs-non-admin error surfacing.
namespace { /* placeholder so the file's first declaration is a namespace */ }

namespace Core\Auth {
    // Stub Auth so the registry's getInstance()->hasRole() resolves
    // here without booting the framework.
    class Auth {
        public static bool $isAdmin = false;
        public static function getInstance(): self { return new self(); }
        public function hasRole($r): bool { return self::$isAdmin; }
    }
}

namespace {
    define('BASE_PATH', dirname(__DIR__, 2));
    spl_autoload_register(function (string $class) {
        if (strpos($class, 'Core\\Auth\\') === 0) return; // already defined above
        if (strpos($class, 'Core\\') !== 0) return;
        $f = BASE_PATH . '/core/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($f)) require $f;
    });

    $reg = new \Core\Module\BlockRegistry();
    $reg->registerMany([
        new \Core\Module\BlockDescriptor(
            key: 'demo.boom',
            label: 'Boom',
            description: 'Always throws',
            category: 'Demo',
            render: function (array $ctx, array $s): string {
                throw new \LogicException('synthetic explode for testing');
            },
        ),
    ]);

    \Core\Auth\Auth::$isAdmin = false;
    $nonAdminHtml = $reg->render('demo.boom');
    echo 'non-admin: ' . (trim($nonAdminHtml) === '' ? "✅ silent ''\n" : "❌ leaked: $nonAdminHtml\n");

    \Core\Auth\Auth::$isAdmin = true;
    $adminHtml = $reg->render('demo.boom');
    $hasErrCard = strpos($adminHtml, 'Block render failed') !== false
                && strpos($adminHtml, 'LogicException') !== false
                && strpos($adminHtml, 'synthetic explode') !== false;
    echo 'admin    : ' . ($hasErrCard ? "✅ admin error card rendered\n" : "❌ no error card:\n$adminHtml\n");
}
