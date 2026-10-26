<?php
declare(strict_types=1);

/** Read metadata from the uploaded APK itself. Does not execute tools or extract files. */
function appApkMetadata(string $path): array {
    if (!class_exists(ZipArchive::class)) fail(503, '服务器需要启用 PHP zip 扩展');
    if (!is_file($path) || !is_readable($path) || filesize($path) > 134217728) fail(422, 'APK 文件无效或超过 128MB');
    $zip = new ZipArchive();
    if (@$zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) fail(422, 'APK 不是有效的 ZIP 文件');
    try {
        if ($zip->numFiles > 20000) fail(422, 'APK 文件项目过多');
        $manifest = null; $dex = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if ($entry === false) fail(422, 'APK 文件目录损坏');
            if ($entry['name'] === 'AndroidManifest.xml') {
                if ($manifest !== null) fail(422, 'APK 含有重复的 AndroidManifest.xml');
                if ($entry['size'] < 8 || $entry['size'] > 2097152 || $entry['comp_size'] > 2162688 || ($entry['encryption_method'] ?? 0) !== 0) fail(422, 'APK 清单过大或已加密');
                $manifest = $entry;
            }
            if ($entry['name'] === 'classes.dex') {
                if ($dex || $entry['size'] < 1) fail(422, 'APK classes.dex 无效');
                $dex = true;
            }
        }
        if ($manifest === null || !$dex) fail(422, 'APK 必须包含 AndroidManifest.xml 和 classes.dex');
        // The length argument prevents decompression beyond the manifest budget, even
        // if the ZIP's declared uncompressed size is dishonest.
        $xml = @$zip->getFromIndex($manifest['index'], 2097153);
        if (!is_string($xml) || strlen($xml) !== $manifest['size'] || strlen($xml) > 2097152 || (int)hexdec(hash('crc32b', $xml)) !== (int)$manifest['crc']) fail(422, 'APK 清单数据损坏');
        return (new AppApkManifestReader($xml))->metadata();
    } finally {
        $zip->close();
    }
}

/** AXML structures: AOSP libs/androidfw/include/androidfw/ResourceTypes.h. */
final class AppApkManifestReader {
    private const NONE = 0xffffffff;
    private const ANDROID = 'http://schemas.android.com/apk/res/android';
    private array $strings = [];
    private array $resources = [];
    private array $elements = [];
    private array $namespaces = [];
    private ?array $result = null;
    public function __construct(private string $xml) {}
    private function bad(): never { fail(422, 'APK AndroidManifest.xml 格式或版本信息无效'); }
    private function need(int $p, int $n, int $end): void {
        if ($p < 0 || $n < 0 || $p > $end || $n > $end - $p || $end > strlen($this->xml)) $this->bad();
    }
    private function u16(int $p): int { $this->need($p, 2, strlen($this->xml)); return unpack('v', $this->xml, $p)[1]; }
    private function u32(int $p): int { $this->need($p, 4, strlen($this->xml)); return unpack('V', $this->xml, $p)[1]; }
    private function str(int $index, bool $optional = false): ?string {
        if ($optional && $index === self::NONE) return null;
        if (!array_key_exists($index, $this->strings)) $this->bad();
        return $this->strings[$index];
    }
    private function length(int &$p, int $end, bool $utf8): int {
        $this->need($p, $utf8 ? 1 : 2, $end);
        if ($utf8) {
            $n = ord($this->xml[$p++]);
            if ($n & 0x80) { $this->need($p, 1, $end); $n = (($n & 0x7f) << 8) | ord($this->xml[$p++]); }
        } else {
            $n = $this->u16($p); $p += 2;
            if ($n & 0x8000) { $this->need($p, 2, $end); $n = (($n & 0x7fff) << 16) | $this->u16($p); $p += 2; }
        }
        return $n;
    }
    private function pool(int $p, int $header, int $end): void {
        if ($header < 28) $this->bad();
        $count = $this->u32($p + 8); $styles = $this->u32($p + 12); $flags = $this->u32($p + 16);
        $start = $this->u32($p + 20); $styleStart = $this->u32($p + 24);
        // Styled strings have no role in this manifest; reject unsupported pools.
        if ($count > 65536 || $styles !== 0 || $styleStart !== 0 || ($flags & ~0x101) !== 0 || $start < $header + $count * 4 || $start > $end - $p || ($start % 4) !== 0) $this->bad();
        $this->need($p + $header, $count * 4, $end);
        $utf8 = (bool)($flags & 0x100); $cache = []; $decodedBytes = 0;
        for ($i = 0; $i < $count; $i++) {
            $offset = $this->u32($p + $header + $i * 4);
            if (!array_key_exists($offset, $cache)) {
                if (!$utf8 && ($offset % 2) !== 0) $this->bad();
                $q = $p + $start + $offset;
                $units = $this->length($q, $end, $utf8);
                $bytes = $utf8 ? $this->length($q, $end, true) : $units * 2;
                $this->need($q, $bytes + ($utf8 ? 1 : 2), $end);
                $raw = substr($this->xml, $q, $bytes);
                if (($utf8 ? ord($this->xml[$q + $bytes]) : $this->u16($q + $bytes)) !== 0) $this->bad();
                if (!mb_check_encoding($raw, $utf8 ? 'UTF-8' : 'UTF-16LE')) $this->bad();
                $value = $utf8 ? $raw : mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
                if (strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) !== $units * 2 || str_contains($value, "\0")) $this->bad();
                $decodedBytes += strlen($value);
                if ($decodedBytes > 4194304) $this->bad();
                $cache[$offset] = $value;
            }
            $this->strings[$i] = $cache[$offset];
        }
    }
    public function metadata(): array {
        $n = strlen($this->xml);
        if ($n < 8 || $n > 2097152 || $this->u16(0) !== 3 || $this->u16(2) !== 8 || $this->u32(4) !== $n) $this->bad();
        $poolSeen = false; $mapSeen = false; $rootEnded = false;
        for ($p = 8; $p < $n; $p = $end) {
            $this->need($p, 8, $n);
            $type = $this->u16($p); $header = $this->u16($p + 2); $size = $this->u32($p + 4);
            if ($header < 8 || $size < $header || ($size % 4) !== 0 || ($header % 4) !== 0) $this->bad();
            $this->need($p, $size, $n); $end = $p + $size;
            if ($type === 1) {
                if ($poolSeen || $this->result !== null) $this->bad();
                $this->pool($p, $header, $end); $poolSeen = true; continue;
            }
            if (!$poolSeen) $this->bad();
            if ($type === 0x180) {
                if ($mapSeen || $this->result !== null || $header !== 8 || ($size - 8) / 4 > count($this->strings)) $this->bad();
                for ($i = $p + 8; $i < $end; $i += 4) $this->resources[] = $this->u32($i);
                $mapSeen = true; continue;
            }
            if ($header !== 16) $this->bad();
            $this->str($this->u32($p + 12), true); $q = $p + $header;
            if ($type === 0x100 || $type === 0x101) {
                $this->need($q, 8, $end);
                if ($end !== $q + 8) $this->bad();
                $ns = [$this->str($this->u32($q), true), $this->str($this->u32($q + 4)), count($this->elements)];
                if ($type === 0x100) {
                    if ($rootEnded || count($this->namespaces) >= 256) $this->bad();
                    $this->namespaces[] = $ns;
                } elseif (!$this->namespaces || array_pop($this->namespaces) !== $ns) $this->bad();
            } elseif ($type === 0x102) {
                if ($rootEnded) $this->bad();
                $this->need($q, 20, $end);
                $ns = $this->str($this->u32($q), true); $name = $this->str($this->u32($q + 4));
                $start = $this->u16($q + 8); $width = $this->u16($q + 10); $count = $this->u16($q + 12);
                if ($start < 20 || ($start % 4) !== 0 || $width < 20 || ($width % 4) !== 0 || count($this->elements) >= 256) $this->bad();
                for ($j = 14; $j <= 18; $j += 2) if ($this->u16($q + $j) > $count) $this->bad();
                $this->need($q + $start, $count * $width, $end);
                if ($end !== $q + $start + $count * $width) $this->bad();
                $root = !$this->elements;
                if ($root && ($name !== 'manifest' || $ns !== null || $this->result !== null)) $this->bad();
                $attributes = []; $seen = [];
                for ($i = 0; $i < $count; $i++) {
                    $a = $q + $start + $i * $width;
                    $ans = $this->str($this->u32($a), true); $keyIndex = $this->u32($a + 4); $key = $this->str($keyIndex);
                    $raw = $this->str($this->u32($a + 8), true); $valueType = ord($this->xml[$a + 15]); $value = $this->u32($a + 16);
                    if ($this->u16($a + 12) !== 8 || ord($this->xml[$a + 14]) !== 0) $this->bad();
                    $typedString = $valueType === 3 ? $this->str($value) : null;
                    $unique = ($ans ?? '')."\0".$key;
                    if (isset($seen[$unique])) $this->bad();
                    $seen[$unique] = true;
                    if (!$root) continue;
                    foreach ([0x0101021b => 'versionCode', 0x0101021c => 'versionName', 0x01010576 => 'versionCodeMajor'] as $id => $expectedKey) {
                        if (($this->resources[$keyIndex] ?? null) === $id && ($key !== $expectedKey || $ans !== self::ANDROID)) $this->bad();
                    }
                    if ($key === 'package' && $ans === null) {
                        if ($valueType !== 3 || ($raw !== null && $raw !== $typedString)) $this->bad();
                        $attributes['package_name'] = $typedString;
                    }
                    if ($ans !== self::ANDROID) continue;
                    if (in_array($key, ['versionCode', 'versionName', 'versionCodeMajor'], true)) {
                        $expected = ['versionCode' => 0x0101021b, 'versionName' => 0x0101021c, 'versionCodeMajor' => 0x01010576][$key];
                        if (($this->resources[$keyIndex] ?? null) !== $expected) $this->bad();
                        if ($key === 'versionName') {
                            if ($valueType !== 3 || ($raw !== null && $raw !== $typedString)) $this->bad();
                            $attributes['version_name'] = $typedString;
                        } else {
                            if ($valueType !== 0x10 && $valueType !== 0x11) $this->bad();
                            if ($raw !== null && (!preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) || (string)$value !== $raw)) $this->bad();
                            if ($key === 'versionCodeMajor') { if ($value !== 0) $this->bad(); }
                            else $attributes['version_code'] = $value;
                        }
                    }

                }
                if ($root) {
                    if (($attributes['package_name'] ?? '') !== 'com.simmanager.collector' || !isset($attributes['version_code'], $attributes['version_name']) || $attributes['version_code'] < 1 || $attributes['version_code'] > 2147483647 || !preg_match('/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $attributes['version_name']) || strlen($attributes['version_name']) > 100) $this->bad();
                    $this->result = $attributes;
                }
                $this->elements[] = [$ns, $name];
            } elseif ($type === 0x103) {
                $this->need($q, 8, $end);
                if ($end !== $q + 8) $this->bad();
                $element = [$this->str($this->u32($q), true), $this->str($this->u32($q + 4))];
                if (!$this->elements || array_pop($this->elements) !== $element) $this->bad();
                if (!$this->elements) $rootEnded = true;
            } elseif ($type === 0x104) {
                $this->need($q, 12, $end);
                if ($end !== $q + 12 || !$this->elements || $this->u16($q + 4) !== 8 || ord($this->xml[$q + 6]) !== 0) $this->bad();
                $this->str($this->u32($q));
                if (ord($this->xml[$q + 7]) === 3) $this->str($this->u32($q + 8));
            } else $this->bad();
        }
        if (!$rootEnded || $this->elements || $this->namespaces || $this->result === null) $this->bad();
        return ['package_name' => $this->result['package_name'], 'version_code' => $this->result['version_code'], 'version_name' => $this->result['version_name']];
    }
}
