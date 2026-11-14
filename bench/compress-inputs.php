<?php

declare(strict_types=1);

use Charm\Zstd\Tests\Inputs;

/** The inputs of compress.php (fixed seeds, built here). */
function inputs(): array
{
    $in = [];
    $in['text'] = Inputs::text(600000, 11) . "\n";
    // source-like: indentation, keywords, identifiers
    mt_srand(4);
    $kw = ['function', 'return', 'foreach', 'if', 'else', 'public', 'private', 'static', 'self::', '$this->', 'array_map', 'strlen', 'throw new', 'null', 'true', 'false'];
    $s = '';
    while (strlen($s) < 600000) {
        $s .= str_repeat('    ', mt_rand(0, 4)) . $kw[mt_rand(0, 15)] . ' $' . $kw[mt_rand(0, 15)] . mt_rand(0, 99) . ' = ' . $kw[mt_rand(0, 15)] . '(' . mt_rand(0, 9999) . ");\n";
    }
    $in['code'] = $s;
    $in['words'] = Inputs::text(600000, 7);
    mt_srand(5);
    $d = '';
    while (strlen($d) < 600000) {
        $d .= mt_rand(0, 99999999) . "\n";
    }
    $in['digits'] = $d;
    mt_srand(6);
    $j = '';
    $names = ['alice', 'bob', 'carol', 'dave', 'erin', 'frank', 'grace', 'heidi'];
    for ($i = 0; strlen($j) < 600000; $i++) {
        $j .= '{"id":' . $i . ',"name":"' . $names[mt_rand(0, 7)] . '","score":' . mt_rand(0, 1000) / 10 . ',"active":' . (mt_rand(0, 1) ? 'true' : 'false') . ',"tags":["a","b"]}' . "\n";
    }
    $in['json'] = $j;
    $in['binary'] = Inputs::get('mixed');
    $p = '';
    mt_srand(8);
    $v = 1700000000000;
    for ($i = 0; $i < 75000; $i++) {
        $v += mt_rand(0, 5000);
        $p .= pack('P', $v);
    }
    $in['int64'] = $p;
    mt_srand(9);
    $p = '';
    for ($i = 0; $i < 600000; $i++) {
        $p .= chr((int) (mt_rand(0, 999) * mt_rand(0, 999) / 62500));
    }
    $in['dict-idx'] = $p;
    mt_srand(10);
    $r = '';
    for ($i = 0; $i < 200000; $i++) {
        $r .= chr(mt_rand(0, 255));
    }
    $in['random'] = $r;
    return $in;
}
