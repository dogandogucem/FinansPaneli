<?php
declare(strict_types=1);
date_default_timezone_set('Europe/Istanbul');
require __DIR__ . '/btso-tufe.php';
function esc(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(float $value): string { return number_format($value, 2, ',', '.'); }
$rates = []; $error = null; $result = null; $latest = null;
$amountInput = is_string($_GET['amount'] ?? null) ? $_GET['amount'] : '10000';
$defaultEnd = new DateTimeImmutable('first day of last month');
$firstInput = is_string($_GET['first'] ?? null) ? $_GET['first'] : '01/' . date('Y');
$lastInput = is_string($_GET['last'] ?? null) ? $_GET['last'] : $defaultEnd->format('m/Y');
$year = (int)date('Y');
function inputPeriod(string $input): DateTimeImmutable {
    if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', trim($input), $parts)) throw new RuntimeException('Tarihi AA/YYYY biçiminde girin. Örnek: 01/2025.');
    if ((int)$parts[2] < 2003 || (int)$parts[2] > (int)date('Y')) throw new RuntimeException('Yıl 2003 ile ' . date('Y') . ' arasında olmalı.');
    return new DateTimeImmutable($parts[2] . '-' . $parts[1] . '-01');
}
try {
    $first = inputPeriod($firstInput);
    $last = inputPeriod($lastInput);
    if ($first > $last) throw new RuntimeException('Başlangıç dönemi bitiş döneminden sonra olamaz.');
    if ($last > new DateTimeImmutable('first day of this month')) throw new RuntimeException('Gelecek dönem seçilemez.');
    $year = (int)$last->format('Y');
    $rates = loadBtsoTufe($year);
    $latest = $rates[array_key_last($rates)];
    if (isset($_GET['calculate'])) {
        $normalized = str_replace(',', '.', trim($amountInput));
        if (!is_numeric($normalized)) throw new RuntimeException('Tutarı binlik ayırıcı kullanmadan girin.');
        $amount = (float)$normalized;
        if (!is_finite($amount) || $amount <= 0) throw new RuntimeException('Sıfırdan büyük bir maliyet girin.');
        $calculated = $amount;
        for ($y = (int)$first->format('Y'); $y <= $year; $y++) {
            $yearRates = $y === $year ? $rates : loadBtsoTufe($y);
            $fromMonth = $y === (int)$first->format('Y') ? (int)$first->format('m') : 1;
            $toMonth = $y === $year ? (int)$last->format('m') : 12;
            $calculated = inflationCost($calculated, $yearRates, $fromMonth, $toMonth);
        }
        $result = $calculated;
    }
} catch (Throwable $exception) { $error = $exception->getMessage(); }
?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TÜFE ile Maliyet Hesabı</title>
<style>
:root{font-family:system-ui,Arial;color:#e5ecf8;background:#0c1425}*{box-sizing:border-box}body{margin:0}main{max-width:1100px;margin:auto;padding:32px 20px}a{color:#93c5fd}h1{margin:24px 0 8px}p,small{color:#a5b8d3}.panel,.card{background:#142138;border:1px solid #263955;border-radius:16px;padding:24px;margin:20px 0}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.card{margin:0}.card strong{display:block;font-size:28px;margin-top:12px}.form{display:flex;gap:16px;flex-wrap:wrap;align-items:end}label{display:flex;flex-direction:column;gap:8px;flex:1;min-width:130px}input,select,button{font:inherit;border-radius:9px;padding:12px;border:1px solid #426080;background:#0c1425;color:#e5ecf8}button{background:#60a5fa;color:#0c1425;cursor:pointer;font-weight:600}.error{background:#56232a;padding:18px;border-radius:12px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #263955}.scroll{overflow:auto}@media(max-width:650px){.cards{grid-template-columns:1fr}}
</style></head><body><main><a href="index.php">← Finans paneli</a><h1>TÜFE ile maliyet hesabı</h1><p>BTSO aylık TÜFE oranlarıyla seçilen dönemin maliyet karşılığını hesaplayın.</p>
<form class="panel form" method="get"><label>Başlangıç maliyeti (TL)<input name="amount" inputmode="decimal" value="<?=esc($amountInput)?>" required placeholder="10000,00"></label><label>İlk uygulanacak ay / yıl<input type="text" name="first" value="<?=esc($firstInput)?>" placeholder="01/2025" pattern="(0[1-9]|1[0-2])/[0-9]{4}" maxlength="7" inputmode="text" required></label><label>Son uygulanacak ay / yıl<input type="text" name="last" value="<?=esc($lastInput)?>" placeholder="09/2026" pattern="(0[1-9]|1[0-2])/[0-9]{4}" maxlength="7" inputmode="text" required></label><button name="calculate" value="1">Maliyeti hesapla</button></form>
<?php if ($error): ?><p class="error" role="alert"><?=esc($error)?></p><?php endif; ?>
<?php if ($rates): ?>
<h2>Son yayımlanan dönem: <?=esc($latest['label'])?></h2><div class="cards"><div class="card">Aylık TÜFE<strong>%<?=money($latest['monthly'])?></strong><small>Bir önceki aya göre</small></div><div class="card">Yıllık TÜFE<strong>%<?=money($latest['annual'])?></strong><small>Bir önceki yılın aynı ayına göre</small></div><div class="card">Kaynak<strong>BTSO</strong><a href="https://www.btso.org.tr/bilgi-bankasi/istatistikler-ve-raporlar/ufe-tufe-oranlari/<?=$year?>" target="_blank" rel="noopener">Kaynak tabloyu aç</a></div></div>

<p>Başlangıç maliyeti, ilk seçilen aydan önceki ay sonuna aittir. İlk ve son ay dahil aylık TÜFE oranları bileşik uygulanır. Tutarı binlik ayırıcı olmadan girin. Dönemleri AA/YYYY biçiminde yazın; farklı yıllar arasında hesaplama yapabilirsiniz.</p>
<?php if ($result !== null): ?><div class="cards" aria-live="polite"><div class="card">TÜFE ile güncellenen maliyet<strong><?=money($result)?> TL</strong></div><div class="card">Maliyet artışı<strong><?=money($result-$amount)?> TL</strong></div><div class="card">Dönemsel değişim<strong>%<?=money(($result/$amount-1)*100)?></strong></div></div><?php endif; ?>
<section class="panel"><h2><?=$year?> TÜFE oranları</h2><div class="scroll"><table><thead><tr><th>Dönem</th><th>Aylık (%)</th><th>Yıllık (%)</th></tr></thead><tbody><?php foreach(array_reverse($rates,true) as $r): ?><tr><td><?=esc($r['label'])?></td><td><?=money($r['monthly'])?></td><td><?=money($r['annual'])?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?></main></body></html>
