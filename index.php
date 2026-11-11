<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Istanbul');

$series = 'TP.PBD.H17';
$key = trim((string)(getenv('TCMB_EVDS_API_KEY') ?: 'v2EEUxV6Yn'));
$error = null;
$points = [];
$diagnostics = [];

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function numericValue(mixed $v): ?float {
    if ($v === null || is_bool($v) || is_array($v) || is_object($v)) return null;
    $s = trim((string)$v);
    if ($s === '' || in_array(strtolower($s), ['-', 'null', 'nan', 'na', 'n/a'], true)) return null;
    $s = str_replace(["\xc2\xa0", ' '], '', $s);
    if (str_contains($s, ',') && str_contains($s, '.')) {
        $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
    } elseif (str_contains($s, ',')) $s = str_replace(',', '.', $s);
    return is_numeric($s) && is_finite((float)$s) ? (float)$s : null;
}
function parseDate(mixed $value): ?DateTimeImmutable {
    if (!is_scalar($value) || trim((string)$value) === '') return null;
    $s = trim((string)$value);
    // EVDS monthly dates can contain an unpadded month, for example 2026-1.
    if (preg_match('/^(\d{4})-(\d{1,2})$/', $s, $parts)) {
        if (!checkdate((int)$parts[2], 1, (int)$parts[1])) return null;
        $s = sprintf('%04d-%02d', (int)$parts[1], (int)$parts[2]);
    }
    foreach (['!d-m-Y', '!d.m.Y', '!Y-m-d', '!Y/m/d', '!d/m/Y', '!m-Y', '!Y-m', '!Y'] as $format) {
        $d = DateTimeImmutable::createFromFormat($format, $s);
        if ($d && $d->format(substr($format, 1)) === $s) return $d;
    }
    return null;
}
function normalizedKey(string $key): string { return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $key)); }
function getItems(array $json): ?array {
    foreach (['items', 'Items', 'ITEMS', 'SERIE_DATA', 'serie_data', 'data', 'Data'] as $k) {
        if (isset($json[$k]) && is_array($json[$k])) return $json[$k];
    }
    if (array_is_list($json)) return $json;
    return null;
}
function previousPoint(array $points, DateTimeImmutable $target, int $maxGapDays = 35): ?array {
    $best = null;
    foreach ($points as $p) {
        if ($p['date'] > $target) break;
        $best = $p;
    }
    if (!$best) return null;
    $gap = (int)$best['date']->diff($target)->format('%a');
    return $gap <= $maxGapDays ? $best : null;
}
function comparisonDate(DateTimeImmutable $date, int $months): DateTimeImmutable {
    $month = $date->modify('first day of this month')->modify('-' . $months . ' months');
    $day = min((int)$date->format('d'), (int)$month->format('t'));
    return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), $day);
}
function percentChange(?float $now, ?float $before): ?float {
    return $now !== null && $before !== null && $before != 0.0 ? ($now / $before - 1) * 100 : null;
}

$today = new DateTimeImmutable('today');
$selectedDate = $today;
$dateInput = $_GET['date'] ?? $today->format('Y-m-d');
if (!is_string($dateInput) || !($parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput)) || $parsedDate->format('Y-m-d') !== $dateInput) {
    $error = 'Lütfen geçerli bir tarih girin.';
} elseif ($parsedDate > $today) {
    $error = 'Bugünden sonraki bir tarih seçemezsiniz.';
} else {
    $selectedDate = $parsedDate;
}

$indexFromInput = is_string($_GET['index_from'] ?? null) ? $_GET['index_from'] : $selectedDate->modify('-1 year')->format('Y-m-d');
$indexAmountInput = is_string($_GET['index_amount'] ?? null) ? $_GET['index_amount'] : '';
$indexError = null; $indexResult = null; $indexBase = null; $indexFrom = null;
if (isset($_GET['index_calculate'])) {
    $indexFrom = DateTimeImmutable::createFromFormat('!Y-m-d', $indexFromInput);
    $rawAmount = trim($indexAmountInput);
    if (!$indexFrom || $indexFrom->format('Y-m-d') !== $indexFromInput || $indexFrom > $selectedDate || (int)$indexFrom->format('Y') < 1900) {
        $indexError = 'Başlangıç tarihi analiz tarihinden sonra olamaz; geçerli bir tarih girin.';
        $indexFrom = null;
    } elseif (!preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/', $rawAmount)) {
        $indexError = 'Geçerli bir tutar girin (örnek: 10.000,00).';
    } else {
        $indexAmount = (float)str_replace(',', '.', str_replace('.', '', $rawAmount));
        if (!is_finite($indexAmount) || $indexAmount <= 0) $indexError = 'Sıfırdan büyük bir tutar girin.';
    }
}
if ($error !== null) {
    // Invalid date input: display the form without requesting EVDS data.
} elseif ($key === '') {
    $error = 'TCMB_EVDS_API_KEY ortam değişkeni tanımlı değil. API anahtarınızı sunucu ortamında tanımlayın.';
} elseif (!function_exists('curl_init')) {
    $error = 'PHP cURL eklentisi etkin değil.';
} else {
    $end = $selectedDate;
    $start = $end->modify('-3 years');
    if ($indexFrom && $indexError === null) {
        $indexQueryStart = $indexFrom->modify('-40 days');
        if ($indexQueryStart < $start) $start = $indexQueryStart;
    }
    $query = http_build_query([
        'series' => $series,
        'startDate' => $start->format('d-m-Y'),
        'endDate' => $end->format('d-m-Y'),
        'type' => 'json',
        'formulas' => '0',
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://evds3.tcmb.gov.tr/igmevdsms-dis/?' . $query;
    // Some EVDS 3 installations use the path-style endpoint.
    $urls = [$url, 'https://evds3.tcmb.gov.tr/igmevdsms-dis/' . $query];
    $raw = false; $status = 0; $curlError = '';
    foreach ($urls as $candidateUrl) {
        $ch = curl_init($candidateUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['key: ' . $key, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($raw !== false && $status === 200) break;
        if ($status === 401 || $status === 403) break;
    }
    if ($raw === false || $status !== 200) {
        $error = 'EVDS bağlantı hatası (HTTP ' . $status . '). ' . ($curlError ?: 'API anahtarı, seri yetkisi veya sorgu adresini kontrol edin.');
        $diagnostics[] = 'Yanıt örneği: ' . mbSafeSnippet((string)$raw);
    } else {
        $json = json_decode((string)$raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            $error = 'EVDS geçerli JSON döndürmedi: ' . json_last_error_msg();
            $diagnostics[] = 'Yanıt örneği: ' . mbSafeSnippet((string)$raw);
        } else {
            $items = getItems($json);
            if ($items === null) {
                $error = 'EVDS yanıtında veri listesi bulunamadı.';
                $diagnostics[] = 'Üst seviye alanlar: ' . implode(', ', array_keys($json));
            } else {
                $expected = normalizedKey($series);
                $firstKeys = [];
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    if (!$firstKeys) $firstKeys = array_keys($item);
                    $date = null;
                    foreach ($item as $field => $value) {
                        if (in_array(normalizedKey((string)$field), ['TARIH', 'DATE'], true)) {
                            $date = parseDate($value);
                            if ($date) break;
                        }
                    }
                    if (!$date) continue;
                    $value = null;
                    foreach ($item as $field => $candidate) {
                        if (normalizedKey((string)$field) === $expected) {
                            $value = numericValue($candidate);
                            break;
                        }
                    }
                    if ($value !== null) $points[$date->format('Y-m-d')] = ['date' => $date, 'value' => $value];

                }
                ksort($points);
                $points = array_values($points);
                if (!$points) {
                    $error = 'Seri için sayısal gözlem bulunamadı.';
                    $diagnostics[] = 'Kayıt sayısı: ' . count($items);
                    $diagnostics[] = 'İlk kaydın alanları: ' . implode(', ', $firstKeys);
                    $diagnostics[] = 'Beklenen seri alanı: ' . str_replace('.', '_', $series);
                    $diagnostics[] = 'Seri kodunu, veri sıklığını ve API erişim yetkisini kontrol edin.';
                }
            }
        }
    }
}
function mbSafeSnippet(string $s): string { return substr(strip_tags($s), 0, 300); }
$latest = $points ? $points[count($points) - 1] : null;
if (isset($_GET['index_calculate']) && $indexError === null) {
    if (!$latest) {
        $indexError = 'Endeksleme için seri verisi alınamadı.';
    } else {
        $indexBase = previousPoint($points, $indexFrom, 35);
        if (!$indexBase || $indexBase['value'] <= 0) {
            $indexError = 'Başlangıç tarihinde veya önceki 35 gün içinde uygun seri verisi bulunamadı.';
        } elseif ($indexBase['date'] > $latest['date']) {
            $indexError = 'Bitiş dönemi için uygun seri verisi bulunamadı.';
        } else {
            $indexResult = $indexAmount * ($latest['value'] / $indexBase['value']);
            if (!is_finite($indexResult)) { $indexResult = null; $indexError = 'Tutar hesaplama sınırını aşıyor.'; }
        }
    }
}
$comparisonGroups = [];
foreach ([$series => $points] as $code => $observations) {
    $last = $observations ? $observations[count($observations) - 1] : null;
    $cards = [];
    foreach (['Aylık' => 1, '3 Aylık' => 3, 'Yıllık' => 12] as $label => $months) {
        $target = $last ? comparisonDate($last['date'], $months) : null;
        $base = $target ? previousPoint($observations, $target) : null;
        $cards[] = ['label' => $label, 'value' => percentChange($last['value'] ?? null, $base['value'] ?? null), 'base' => $base];
    }
    $comparisonGroups[] = ['series' => $code, 'latest' => $last, 'cards' => $cards];
}
$primaryValues = [];
foreach ($points as $point) $primaryValues[$point['date']->format('Y-m-d')] = $point['value'];
$chartDates = array_keys($primaryValues);
sort($chartDates);
$chartDates = array_slice($chartDates, -80);
$labels = array_map(static fn($date) => (new DateTimeImmutable($date))->format('d.m.Y'), $chartDates);
$values = array_map(static fn($date) => $primaryValues[$date] ?? null, $chartDates);
require_once __DIR__ . '/btso-tufe.php';
$marketData = null;
$marketError = null;
try {
    require_once __DIR__ . '/doviz-fiyatlari.php';
    $marketData = fetchDovizPrices();
} catch (Throwable $exception) {
    $marketError = $exception->getMessage();
}
$inflationLatest = null;
$inflationError = null;
$inflationYear = (int)date('Y');
try {
    try {
        $inflationRates = loadBtsoTufe($inflationYear);
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'tablosu bulunamadı')) throw $exception;
        $inflationYear--;
        $inflationRates = loadBtsoTufe($inflationYear);
    }
    $inflationLatest = $inflationRates[array_key_last($inflationRates)];
    $inflationDate = DateTimeImmutable::createFromFormat('!Y-n-j', $inflationYear . '-' . $inflationLatest['month'] . '-1');
    $inflationMonth = $inflationDate ?: new DateTimeImmutable($inflationYear . '-01-01');
    $monthlyComparison = $inflationMonth->format('m/Y') . ' ↔ ' . $inflationMonth->modify('-1 month')->format('m/Y');
    $annualComparison = $inflationMonth->format('m/Y') . ' ↔ ' . $inflationMonth->modify('-1 year')->format('m/Y');
    $average12Comparison = $inflationMonth->modify('-11 months')->format('m/Y') . '–' . $inflationMonth->format('m/Y') . ' ↔ ' . $inflationMonth->modify('-23 months')->format('m/Y') . '–' . $inflationMonth->modify('-12 months')->format('m/Y');
} catch (Throwable $exception) {
    $inflationError = $exception->getMessage();
}
?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>EVDS Finans Paneli</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
<style>
:root{font-family:Inter,system-ui,Arial,sans-serif;color:#1f2937;background:#f3f6fa}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1250px;margin:auto;padding:35px 22px}header{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}h1{margin:0;font-size:29px}p,.muted{color:#64748b}.tag{background:#dbeafe;padding:9px 14px;border-radius:25px;color:#1d4ed8}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:30px 0}.card,.panel{background:#ffffff;border:1px solid #dbe3ee;border-radius:16px;padding:23px}.card strong{font-size:31px;display:block;margin:16px 0}.card small{color:#64748b}.positive{color:#dc2626}.negative{color:#059669}.panel{margin-bottom:20px}h2{font-size:19px;margin-top:0}.error{background:#fef2f2;border:1px solid #fca5a5;padding:18px;border-radius:12px;margin-top:20px}.diagnostics{margin-top:12px;color:#92400e;font-size:13px}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:12px;border-bottom:1px solid #dbe3ee}th{color:#475569}canvas{max-height:340px}.scroll{max-height:350px;overflow:auto}@media(max-width:800px){.cards{grid-template-columns:repeat(2,1fr)}}@media(max-width:480px){.cards{grid-template-columns:1fr}}
.date-filter{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-top:24px}.date-filter label{display:flex;flex-direction:column;gap:8px;color:#64748b}.date-filter input,.date-filter button{font:inherit;border-radius:9px;padding:11px 14px}.date-filter input{background:#f3f6fa;color:#1f2937;border:1px solid #cbd5e1;color-scheme:light}.date-filter button{background:#2563eb;color:#f3f6fa;border:0;font-weight:600;cursor:pointer}.date-filter input:focus-visible,.date-filter button:focus-visible{outline:2px solid #1d4ed8;outline-offset:3px}</style></head><body><main class="wrap"><header><div><h1>Finans Paneli</h1></div><span class="tag"><?=h($series)?></span></header>
<section class="panel" aria-labelledby="market-title">
<h2 id="market-title">Piyasa fiyatları</h2>
<?php if ($marketData): ?>
<p>Kaynak: <a style="color:#1d4ed8" href="https://www.doviz.com/" target="_blank" rel="noopener">Döviz.com</a> · Akaryakıt bölgesi: <b><?=h($marketData['fuelLocation'])?></b> · Güncelleme: <?=h($marketData['updatedAt'])?></p>
<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
<div class="card"><span class="muted">Gram Altın</span><strong>₺<?=h(number_format($marketData['prices']['gold'],2,',','.'))?></strong><small>Son satış fiyatı</small></div>
<div class="card"><span class="muted">Gram Gümüş</span><strong>₺<?=h(number_format($marketData['prices']['silver'],2,',','.'))?></strong><small>Son satış fiyatı</small></div>
<div class="card"><span class="muted">USD / TRY</span><strong>₺<?=h(number_format($marketData['prices']['usd']['sell'],4,',','.'))?></strong><small>Alış: ₺<?=h(number_format($marketData['prices']['usd']['buy'],4,',','.'))?> · Satış</small></div>
<div class="card"><span class="muted">Benzin</span><strong>₺<?=h(number_format($marketData['prices']['gasoline'],2,',','.'))?></strong><small>İstanbul Avrupa ortalaması</small></div>
<div class="card"><span class="muted">Motorin / Dizel</span><strong>₺<?=h(number_format($marketData['prices']['diesel'],2,',','.'))?></strong><small>İstanbul Avrupa ortalaması</small></div>
</div>
<?php else: ?><p class="error" role="alert">Piyasa fiyatları şu anda alınamadı: <?=h($marketError)?></p><?php endif; ?>
</section>

<section class="panel" aria-labelledby="inflation-title">
<h2 id="inflation-title">Güncel TÜFE endeksi</h2>
<?php if ($inflationLatest): ?>
<p>Kaynak: <a style="color:#1d4ed8" href="https://www.btso.org.tr/bilgi-bankasi/istatistikler-ve-raporlar/ufe-tufe-oranlari/<?=$inflationYear?>" target="_blank" rel="noopener">BTSO</a> · Son yayımlanan dönem: <b><?=h($inflationLatest['label'])?></b></p>
<div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
<div class="card"><span class="muted">Aylık (TÜFE) Endeksi</span><strong>%<?=h(number_format($inflationLatest['monthly'],2,',','.'))?></strong><small>Karşılaştırma: <?=h($monthlyComparison)?></small></div>
<div class="card"><span class="muted">Yıllık (TÜFE) Endeksi</span><strong>%<?=h(number_format($inflationLatest['annual'],2,',','.'))?></strong><small>Karşılaştırma: <?=h($annualComparison)?></small></div>
<div class="card"><span class="muted">12 Aylık Ortalama TÜFE</span><strong>%<?=h(number_format($inflationLatest['average12'],2,',','.'))?></strong><small>Karşılaştırma: <?=h($average12Comparison)?></small></div>
<div class="card">
<h3 style="margin-top:0">Maliyet hesapla</h3>
<form id="inflation-calculator" class="date-filter" style="margin-top:0">
<label for="inflation-amount" style="width:100%">Başlangıç tutarı (TL)<span style="position:relative;display:block"><span aria-hidden="true" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#1d4ed8">₺</span><input id="inflation-amount" type="text" inputmode="decimal" placeholder="10.000,00" required style="width:100%;padding-left:34px"></span></label>
<label for="inflation-period" style="width:100%">Uygulanacak oran<select id="inflation-period" style="font:inherit;padding:10px;border-radius:9px;background:#f3f6fa;color:#1f2937;border:1px solid #cbd5e1;width:100%"><option value="monthly">Aylık TÜFE</option><option value="annual">Yıllık TÜFE</option><option value="average12">12 Aylık Ortalama TÜFE</option></select></label>
<button type="submit">Hesapla</button>
</form>
<p id="inflation-result" aria-live="polite" hidden></p>
<small>Seçilen oran tutara bir kez uygulanır. Binlik ayırıcı otomatik eklenir; kuruş için virgül kullanın.</small>
</div>
</div>
<?php else: ?><p role="alert">TÜFE verileri şu anda alınamadı: <?=h($inflationError)?></p><?php endif; ?>
</section>
<?php if ($latest): ?>
<?php foreach ($comparisonGroups as $group): ?>
<h2><?=h($group['series'])?> · Yüzdelik değişimler</h2><p>TCMB EVDS 3 · Dönemsel değişim analizi</p>
<?php if ($group['latest']): ?>
<p>Son çekilen verinin tarihi: <b><?=h($group['latest']['date']->format('d.m.Y'))?></b> · Seri değeri: <b><?=h(number_format($group['latest']['value'],3,',','.'))?></b></p>
<?php else: ?><p>Bu seri için uygun veri yok.</p><?php endif; ?>
<section class="cards"><?php foreach ($group['cards'] as $c): ?><div class="card"><span class="muted"><?=h($c['label'])?> değişim</span><strong class="<?= $c['value'] === null ? '' : ($c['value'] >= 0 ? 'positive' : 'negative') ?>"><?= $c['value'] === null ? '—' : '%' . h(number_format($c['value'],2,',','.')) ?></strong><small>Son veri tarihi: <?= $group['latest'] ? h($group['latest']['date']->format('d.m.Y')) : 'Uygun veri yok' ?><br>Karşılaştırma tarihi: <?= $c['base'] ? h($c['base']['date']->format('d.m.Y')) : 'Uygun veri yok' ?></small></div><?php endforeach; ?></section>
<?php endforeach; ?>
<?php endif; ?>

<section class="panel" aria-labelledby="series-index-title">
<h2 id="series-index-title"><?=h($series)?> · Tutar endeksleme</h2>
<form method="get" class="date-filter">
<label for="index-from">Başlangıç tarihi<input type="date" id="index-from" name="index_from" value="<?=h($indexFromInput)?>" max="<?=h($today->format('Y-m-d'))?>" required></label>
<label for="index-to">Bitiş / analiz tarihi<input type="date" id="index-to" name="date" value="<?=h($selectedDate->format('Y-m-d'))?>" max="<?=h($today->format('Y-m-d'))?>" required></label>
<label for="index-amount">Başlangıç tutarı (₺)<span style="position:relative;display:block"><span aria-hidden="true" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#1d4ed8">₺</span><input type="text" id="index-amount" name="index_amount" inputmode="decimal" placeholder="10.000,00" value="<?=h($indexAmountInput)?>" required style="padding-left:34px"></span></label>
<button type="submit" name="index_calculate" value="1">Endeksle</button>
</form>
<p>Tutar × (bitiş seri değeri / başlangıç seri değeri). Tarihlerdeki veya öncesindeki mevcut gözlemler kullanılır.</p>
<?php if ($indexError): ?><p role="alert"><?=h($indexError)?></p><?php endif; ?>
<?php if ($indexResult !== null): ?>
<div class="cards"><div class="card"><span class="muted">Endekslenmiş tutar</span><strong>₺ <?=h(number_format($indexResult,2,',','.'))?></strong></div><div class="card"><span class="muted">Tutar farkı</span><strong>₺ <?=h(number_format($indexResult-$indexAmount,2,',','.'))?></strong></div><div class="card"><span class="muted">Dönemsel değişim</span><strong>%<?=h(number_format(($latest['value']/$indexBase['value']-1)*100,2,',','.'))?></strong></div></div>
<p>Başlangıç gözlemi: <b><?=h($indexBase['date']->format('d.m.Y'))?></b> · Seri değeri: <?=h(number_format($indexBase['value'],3,',','.'))?><br>Bitiş gözlemi: <b><?=h($latest['date']->format('d.m.Y'))?></b> · Seri değeri: <?=h(number_format($latest['value'],3,',','.'))?></p>
<?php endif; ?>
</section>
<form method="get" class="panel date-filter">
<label for="date">Analiz tarihi<input type="date" id="date" name="date" value="<?=h(is_string($dateInput) ? $dateInput : $today->format('Y-m-d'))?>" max="<?=h($today->format('Y-m-d'))?>" required></label>
<button type="submit">Göster</button>
</form>
<?php if ($error): ?><div class="error"><?=h($error)?><?php if ($diagnostics): ?><div class="diagnostics"><?php foreach ($diagnostics as $line): ?><div><?=h($line)?></div><?php endforeach; ?></div><?php endif; ?></div><?php endif; ?>
<?php if ($latest): ?>

<section class="panel"><h2>Son 80 gözlem · Seri düzeyi</h2><canvas id="chart"></canvas></section>
<section class="panel"><h2>Son gözlemler</h2><div class="scroll"><table><thead><tr><th>Tarih</th><th>Değer</th></tr></thead><tbody><?php foreach (array_reverse(array_slice($points,-40)) as $p): ?><tr><td><?=h($p['date']->format('d.m.Y'))?></td><td><?=h(number_format($p['value'],3,',','.'))?></td></tr><?php endforeach; ?></tbody></table></div></section>
<p>Hesaplama: (son değer / karşılaştırma değer − 1) × 100. Hedef tarihler son çekilen verinin tarihine göre hesaplanır; ay sonunda gün, hedef ayın son gününü aşamaz. Hedef tarihteki veya öncesindeki yakın gözlem kullanılır. Veri sıklığı ve seri tanımı doğrulanmadan bu değişimler resmî TÜFE enflasyonu olarak yorumlanmamalıdır.</p>
<script>
if(window.Chart){new Chart(document.getElementById('chart'),{type:'line',data:{labels:<?=json_encode($labels,JSON_UNESCAPED_UNICODE)?>,datasets:[{label:<?=json_encode($series)?>,data:<?=json_encode($values)?>,borderColor:'#2563eb',backgroundColor:'rgba(96,165,250,.1)',fill:false,tension:.25,pointRadius:0}]},options:{responsive:true,plugins:{legend:{labels:{color:'#334155'}}},scales:{x:{ticks:{color:'#64748b',maxTicksLimit:9},grid:{color:'#dbe3ee'}},y:{ticks:{color:'#64748b'},grid:{color:'#dbe3ee'}}}}});}
</script>
<?php endif; ?></main><script>
(() => {
    const formatAmount = amountInput => {
        const before = amountInput.value;
        const cursor = amountInput.selectionStart ?? before.length;
        const significantBefore = before.slice(0, cursor).replace(/[^0-9,]/g, '').length;
        const cleaned = before.replace(/[^0-9,]/g, '');
        const comma = cleaned.indexOf(',');
        let whole = comma < 0 ? cleaned : cleaned.slice(0, comma);
        whole = whole.replace(/^0+(?=\d)/, '');
        const fraction = comma < 0 ? '' : ',' + cleaned.slice(comma + 1).replace(/,/g, '').slice(0, 2);
        const formatted = (whole || (comma >= 0 ? '0' : '')).replace(/\B(?=(\d{3})+(?!\d))/g, '.') + fraction;
        amountInput.value = formatted;
        let position = 0, seen = 0;
        while (position < formatted.length && seen < significantBefore) {
            if (/[0-9,]/.test(formatted[position])) seen++;
            position++;
        }
        amountInput.setSelectionRange(position, position);
    };
    document.querySelectorAll('#index-amount, #inflation-amount').forEach(amountInput => {
        amountInput.addEventListener('input', () => formatAmount(amountInput));
        amountInput.addEventListener('blur', () => {
            if (!amountInput.value) return;
            formatAmount(amountInput);
            const parts = amountInput.value.split(',');
            amountInput.value = parts[0] + ',' + (parts[1] || '').padEnd(2, '0');
        });
        if (amountInput.value) formatAmount(amountInput);
    });
    const form = document.getElementById('inflation-calculator');
    if (!form) return;
    const rates = <?=json_encode(['monthly' => $inflationLatest['monthly'] ?? null, 'annual' => $inflationLatest['annual'] ?? null, 'average12' => $inflationLatest['average12'] ?? null])?>;
    const output = document.getElementById('inflation-result');
    const money = new Intl.NumberFormat('tr-TR', {style:'currency', currency:'TRY'});
    form.addEventListener('submit', event => {
        event.preventDefault();
        const raw = document.getElementById('inflation-amount').value.trim();
        const amount = Number(raw.replace(/\./g, '').replace(',', '.'));
        const rate = rates[document.getElementById('inflation-period').value];
        output.hidden = false;
        if (!/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,2})?$/.test(raw) || !Number.isFinite(amount) || amount <= 0 || rate === null) {
            output.textContent = 'Sıfırdan büyük bir tutar girin (örnek: 10.000,00).';
            return;
        }
        const total = amount * (1 + rate / 100);
        if (!Number.isFinite(total)) { output.textContent = 'Tutar çok büyük.'; return; }
        output.textContent = 'Güncellenmiş maliyet: ' + money.format(total) + ' · Fark: ' + money.format(total - amount);
    });
    form.addEventListener('input', () => { output.hidden = true; });
    form.addEventListener('change', () => { output.hidden = true; });
})();
</script></body></html>

