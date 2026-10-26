<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/apk_metadata.php';

set_error_handler(function(int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
function apkCheck(bool $condition, string $message): void { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function apkReject(callable $call, string $message): void {
    try { $call(); } catch (ApiError $e) { apkCheck($e->status === 422, $message.' (status)'); return; }
    throw new RuntimeException('Accepted invalid APK: '.$message);
}

// A tiny independent wire-format fixture, following the AOSP ResourceTypes.h
// structures. Includes unused non-ASCII/long strings to exercise length decoding.
function apkFixture(array $options = []): string {
    $utf8 = $options['utf8'] ?? true;
    $strings = ['versionCode', 'versionName', 'versionCodeMajor', 'package', 'manifest', 'android', 'http://schemas.android.com/apk/res/android', $options['package'] ?? 'com.simmanager.collector', $options['name'] ?? '0.4.7', $options['filler'] ?? str_repeat('界', 140).'😀'];
    $data = ''; $offsets = '';
    $len8 = fn(int $n): string => $n > 127 ? chr(0x80 | ($n >> 8)).chr($n & 255) : chr($n);
    foreach ($strings as $str) {
        $offsets .= pack('V', strlen($data)); $wide = mb_convert_encoding($str, 'UTF-16LE', 'UTF-8');
        $data .= $utf8 ? $len8(intdiv(strlen($wide), 2)).$len8(strlen($str)).$str."\0" : (strlen($wide) / 2 > 32767 ? pack('vv', 0x8000 | (intdiv(strlen($wide), 2) >> 16), intdiv(strlen($wide), 2) & 65535) : pack('v', intdiv(strlen($wide), 2))).$wide."\0\0";
    }
    $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
    $pool = pack('vvVVVVVV', 1, 28, 28 + strlen($offsets.$data), count($strings), 0, $utf8 ? 0x100 : 0, 28 + strlen($offsets), 0).$offsets.$data;
    $map = pack('vvVVVV', 0x180, 8, 20, 0x0101021b, $options['name_resource'] ?? 0x0101021c, 0x01010576);
    $node = fn(int $type, string $body): string => pack('vvVVV', $type, 16, 16 + strlen($body), 1, 0xffffffff).$body;
    $attr = fn(int $ns, int $key, int $raw, int $type, int $value): string => pack('VVVvCCV', $ns, $key, $raw, 8, 0, $type, $value);
    $attrs = $attr(0xffffffff, 3, 7, 3, 7)
        .$attr(6, 0, 0xffffffff, $options['code_type'] ?? 0x10, $options['code'] ?? 11)
        .$attr(6, 1, $options['name_raw'] ?? 8, $options['name_type'] ?? 3, 8);
    if (isset($options['major'])) $attrs .= $attr(6, 2, 0xffffffff, 0x10, $options['major']);
    if ($options['duplicate_attribute'] ?? false) $attrs .= $attr(6, 0, 0xffffffff, 0x10, 12);
    $body = $pool.$map.$node(0x100, pack('VV', 5, 6))
        .$node(0x102, pack('VVvvvvvv', 0xffffffff, 4, 20, 20, intdiv(strlen($attrs), 20), 0, 0, 0).$attrs)
        .$node(0x103, pack('VV', 0xffffffff, 4)).$node(0x101, pack('VV', 5, 6));
    return pack('vvV', 3, 8, 8 + strlen($body)).$body;
}

// Stored ZIP writer deliberately preserves duplicate entry names (ZipArchive's
// addFromString replaces them). This also gives us independent corrupt fixtures.
function apkArchive(array $entries): string {
    $local = ''; $central = ''; $count = 0;
    foreach ($entries as [$name, $data]) {
        $offset = strlen($local); $size = strlen($data); $crc = crc32($data); $namesize = strlen($name);
        $local .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $namesize, 0).$name.$data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $namesize, 0, 0, 0, 0, 0, $offset).$name;
        $count++;
    }
    return $local.$central.pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
}
function apkChunks(string $xml): array {
    $result = [];
    for ($p = 8; $p < strlen($xml); $p += unpack('V', $xml, $p + 4)[1]) $result[unpack('v', $xml, $p)[1]][] = $p;
    return $result;
}
$tmp = tempnam(sys_get_temp_dir(), 'apk-metadata-');
if ($tmp === false) throw new RuntimeException('tempnam failed');
$read = function(string $xml, bool $dex = true) use ($tmp): array {
    $entries = [['AndroidManifest.xml', $xml]];
    if ($dex) $entries[] = ['classes.dex', "dex\n035\0"];
    file_put_contents($tmp, apkArchive($entries));
    return appApkMetadata($tmp);
};
try {
    $expected = ['package_name' => 'com.simmanager.collector', 'version_code' => 11, 'version_name' => '0.4.7'];
    apkCheck($read(apkFixture()) === $expected, 'UTF-8 metadata incorrect');
    apkCheck($read(apkFixture(['utf8' => false])) === $expected, 'UTF-16 metadata incorrect');
    apkCheck($read(apkFixture(['utf8' => false, 'filler' => str_repeat('a', 40000)])) === $expected, 'UTF-16 extended length incorrect');
    apkCheck($read(apkFixture(['code' => 2147483647, 'code_type' => 0x11]))['version_code'] === 2147483647, 'valid int boundary rejected');
    apkCheck($read(apkFixture(['major' => 0])) === $expected, 'zero major code rejected');
    foreach ([['package' => 'com.other.collector'], ['name' => '0.4'], ['name' => '0.4.7-beta'], ['name' => '00.4.7'], ['code' => 0], ['code' => 0x80000000], ['code' => 0xffffffff], ['code_type' => 1], ['code_type' => 3], ['name_type' => 1], ['name_resource' => 0x01010001], ['name_raw' => 7], ['major' => 1], ['duplicate_attribute' => true]] as $options) {
        apkReject(fn() => $read(apkFixture($options)), 'metadata '.json_encode($options));
    }
    apkReject(fn() => $read(apkFixture(), false), 'missing classes.dex');
    apkReject(fn() => $read('<manifest package="com.simmanager.collector"/>'), 'plain XML is not AXML');
    apkReject(fn() => $read(str_repeat('a', 2097153)), 'manifest over 2 MB');
    $xml = apkFixture(); $chunks = apkChunks($xml); $start = $chunks[0x102][0];
    foreach ([0, 1, 7, 8, 9, strlen($xml) - 1, strlen($xml) - 24] as $length) apkReject(fn() => $read(substr($xml, 0, $length)), 'truncation '.$length);
    $corruptions = [
        'outer size' => [4, pack('V', 0xffffffff)],
        'zero chunk size' => [12, pack('V', 0)],
        'chunk out of range' => [12, pack('V', 0xfffffffc)],
        'pool index count' => [16, pack('V', 0xffffffff)],
        'pool data start' => [28, pack('V', 0xffffffff)],
        'pool offset' => [36, pack('V', 0xffffffff)],
        'bad UTF8' => [8 + 28 + 40 + 2, "\xff"],
        'string length mismatch' => [8 + 28 + 40, "\x7f"],
        'bad attribute name index' => [$start + 16 + 20 + 4, pack('V', 0xfffffffe)],
        'bad value string index' => [$start + 16 + 20 + 16, pack('V', 0xfffffffe)],
        'attribute count overflow' => [$start + 16 + 12, pack('v', 0xffff)],
        'attribute offset overflow' => [$start + 16 + 8, pack('v', 0xffff)],
        'attribute size zero' => [$start + 16 + 10, pack('v', 0)],
        'typed value size' => [$start + 16 + 20 + 12, pack('v', 0)],
        'wrong end element' => [$chunks[0x103][0] + 20, pack('V', 3)],
    ];
    foreach ($corruptions as $label => [$offset, $bytes]) apkReject(fn() => $read(substr_replace($xml, $bytes, $offset, strlen($bytes))), $label);
    file_put_contents($tmp, apkArchive([['AndroidManifest.xml', $xml], ['AndroidManifest.xml', $xml], ['classes.dex', 'dex']]));
    apkReject(fn() => appApkMetadata($tmp), 'duplicate manifest ZIP entries');
    file_put_contents($tmp, apkArchive([['classes.dex', 'dex']]));
    apkReject(fn() => appApkMetadata($tmp), 'missing manifest');
    $crcArchive = apkArchive([['AndroidManifest.xml', $xml], ['classes.dex', 'dex']]);
    file_put_contents($tmp, substr_replace($crcArchive, 'x', 30 + strlen('AndroidManifest.xml') + 100, 1));
    apkReject(fn() => appApkMetadata($tmp), 'CRC mismatch');
    file_put_contents($tmp, 'not zip');
    apkReject(fn() => appApkMetadata($tmp), 'invalid zip');
    apkReject(fn() => appApkMetadata($tmp.'-missing'), 'missing file');
    // Compressed expansion is rejected before inflation.
    $z = new ZipArchive(); $z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFromString('AndroidManifest.xml', str_repeat('x', 3 * 1024 * 1024));
    $z->addFromString('classes.dex', 'dex'); $z->close();
    apkReject(fn() => appApkMetadata($tmp), 'compressed expansion');
    $real = getenv('APK_TEST_PATH') ?: dirname(__DIR__, 2).'/sim-manager/android/app/build/outputs/apk/debug/app-debug.apk';
    if (!is_file($real)) throw new RuntimeException('Real APK missing; set APK_TEST_PATH');
    $actual = appApkMetadata($real);
    $realExpected=$expected;$buildFile=dirname(__DIR__,2).'/sim-manager/android/app/build.gradle.kts';
    if(is_file($buildFile)){$buildText=file_get_contents($buildFile);if(preg_match('/versionCode\s*=\s*(\d+)/',$buildText,$code)&&preg_match('/versionName\s*=\s*"([^"]+)"/',$buildText,$name))$realExpected=['package_name'=>'com.simmanager.collector','version_code'=>(int)$code[1],'version_name'=>$name[1]];}
    apkCheck($actual === $realExpected, 'real APK metadata differs from build version: '.json_encode($actual));
    // Corrupt the actual compiled manifest; the parser must walk past the root
    // metadata and reject a truncated document instead of returning early.
    $z = new ZipArchive(); $z->open($real); $compiled = $z->getFromName('AndroidManifest.xml'); $z->close();
    $truncated = substr($compiled, 0, -4);
    $truncated = substr_replace($truncated, pack('V', strlen($truncated)), 4, 4);
    apkReject(fn() => $read($truncated), 'truncated real manifest with valid outer length');
    $wide = apkFixture(['utf8' => false]);
    $dataStart = 8 + 28 + 40;
    apkReject(fn() => $read(substr_replace($wide, pack('v', 0xd800), $dataStart + 2, 2)), 'unpaired UTF-16 surrogate');
    apkReject(fn() => $read(substr_replace($wide, pack('v', 1), $dataStart + 2 + strlen('versionCode') * 2, 2)), 'missing UTF-16 terminator');
    echo 'PASS: '.$checks.' APK metadata checks; real APK '.json_encode($actual, JSON_UNESCAPED_SLASHES)."\n";
} finally { unlink($tmp); }
