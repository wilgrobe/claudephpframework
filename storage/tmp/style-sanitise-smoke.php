<?php
// Smoke-test the color + bg-url sanitisers. These are the gates that
// stop pasted CSS from becoming inline-CSS-injection on the public page.

define('BASE_PATH', dirname(__DIR__, 2));
spl_autoload_register(function (string $class) {
    if (strpos($class, 'Core\\') !== 0) return;
    $f = BASE_PATH . '/core/' . str_replace('\\', '/', substr($class, 5)) . '.php';
    if (is_file($f)) require $f;
});

// PageLayoutService's constructor wants a Database singleton; the
// sanitisers are static methods that don't need it. Direct call.
use Core\Services\PageLayoutService as P;

$colorCases = [
    ['#fef3c7',                    '#fef3c7'],
    ['#FFFFFF',                    '#ffffff'],
    ['#abc',                       '#abc'],
    ['rgb(255, 0, 0)',             'rgb(255, 0, 0)'],
    ['rgba(0,0,0,.5)',             'rgba(0,0,0,.5)'],
    ['hsl(120 50% 60%)',           'hsl(120 50% 60%)'],
    ['transparent',                'transparent'],
    ['INDIGO',                     'indigo'],
    ['javascript:alert(1)',        ''],
    ['url(http://x)',              ''],
    ['expression(alert(1))',       ''],
    ['" onerror="alert(1)',        ''],
    ['',                           ''],
    ['  red  ',                    'red'],
];
foreach ($colorCases as [$in, $want]) {
    $got = P::sanitiseColor($in);
    echo ($got === $want ? '✅' : '❌'),
         ' color ', json_encode($in), ' -> ', json_encode($got),
         ($got === $want ? '' : "  (wanted " . json_encode($want) . ')'), "\n";
}

echo "\n";

$urlCases = [
    ['https://example.com/img.png',  'https://example.com/img.png'],
    ['/static/bg.jpg',               '/static/bg.jpg'],
    ['data:image/png;base64,iVBOR',  'data:image/png;base64,iVBOR'],
    ['javascript:alert(1)',          ''],
    ['file:///etc/passwd',           ''],
    ['ftp://anon@host/x',            ''],
    ['',                             ''],
];
foreach ($urlCases as [$in, $want]) {
    $got = P::sanitiseBgUrl($in);
    echo ($got === $want ? '✅' : '❌'),
         ' bg_url ', json_encode($in), ' -> ', json_encode($got),
         ($got === $want ? '' : "  (wanted " . json_encode($want) . ')'), "\n";
}

echo "\n";

// Full row-style sanitiser sweep with mixed valid + invalid fields.
$dirty = [
    'bg_color'           => 'javascript:alert(1)',  // → dropped
    'bg_image'           => 'https://ok/x.png',      // → kept
    'full_bleed'         => true,                    // → kept
    'content_padding_px' => 32,                      // → kept
    'padding_px'         => 9999,                    // → dropped (over cap)
    'text_color'         => '#abc',                  // → kept
    'radius_px'          => -10,                     // → dropped (negative)
    'extraneous'         => 'gets ignored',          // → dropped
];
$clean = P::sanitiseRowStyle($dirty);
$expectedKeys = ['bg_image','full_bleed','content_padding_px','text_color'];
sort($expectedKeys);
$gotKeys = array_keys($clean); sort($gotKeys);
echo ($gotKeys === $expectedKeys ? '✅' : '❌'),
     ' row sanitiser produced expected keys: ', json_encode($gotKeys), "\n";
