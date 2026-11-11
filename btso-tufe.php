<?php
declare(strict_types=1);

function parseBtsoTufe(string $html, int $year): array {
    $months = ['Ocak','Şubat','Mart','Nisan','Mayıs','Haziran','Temmuz','Ağustos','Eylül','Ekim','Kasım','Aralık'];
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $result = [];
    foreach ($xpath->query('//table') as $table) {
        $rows = $xpath->query('.//tr', $table);
        $month = null; $monthly = null; $annual = null; $average12 = null;
        foreach ($rows as $row) {
            $cells = $xpath->query('./td|./th', $row);
            if ($cells->length < 3) continue;
            $label = trim(preg_replace('/\s+/u', ' ', $cells->item(0)->textContent));
            foreach ($months as $index => $name) {
                if (preg_match('/^' . preg_quote($name, '/') . '\s*-\s*' . $year . '$/u', $label)) {
                    $month = $index + 1;
                }
            }
            $raw = trim($cells->item(2)->textContent);
            $raw = str_replace(["\xc2\xa0", ' ', '%'], '', $raw);
            if (!preg_match('/^-?\d+(?:,\d+)?$/', $raw)) continue;
            $value = (float)str_replace(',', '.', $raw);
            if ($label === 'Bir Önceki Aya Göre') $monthly = $value;
            if ($label === 'Bir Önceki Yıla Göre') $annual = $value;
            if (str_contains($label, 'On İki Aylık Ortalamalara Göre') || str_contains($label, 'Oniki Aylık Ortalamalara Göre')) $average12 = $value;
        }
        if ($month !== null && $monthly !== null && $annual !== null && $average12 !== null) {
            $result[$month] = ['month' => $month, 'label' => $months[$month - 1] . ' ' . $year, 'monthly' => $monthly, 'annual' => $annual, 'average12' => $average12];
        }
    }
    if (!$result) throw new RuntimeException('BTSO yanıtında aylık, yıllık ve 12 aylık ortalama TÜFE tablosu bulunamadı.');
    ksort($result);
    return $result;
}

function loadBtsoTufe(int $year): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL eklentisi gerekli.');
    $url = 'https://www.btso.org.tr/bilgi-bankasi/istatistikler-ve-raporlar/ufe-tufe-oranlari/' . $year;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Mozilla/5.0', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CAINFO => __DIR__ . '/certs/cacert.pem']);
    $html = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlCode = curl_errno($ch); $curlError = curl_error($ch); curl_close($ch);
    if ($html === false) throw new RuntimeException('BTSO bağlantı hatası (cURL ' . $curlCode . '): ' . $curlError);
    if ($status !== 200) throw new RuntimeException('BTSO verisi alınamadı (HTTP ' . $status . ').');
    return parseBtsoTufe($html, $year);
}

function inflationCost(float $amount, array $rates, int $first, int $last): float {
    if (!is_finite($amount) || $amount <= 0 || $first < 1 || $last > 12 || $first > $last) throw new RuntimeException('Tutar ve ay aralığını kontrol edin.');
    $total = $amount;
    for ($month = $first; $month <= $last; $month++) {
        if (!isset($rates[$month])) throw new RuntimeException('Seçilen aralıkta yayımlanmamış veya eksik ay var.');
        $total *= 1 + $rates[$month]['monthly'] / 100;
    }
    if (!is_finite($total)) throw new RuntimeException('Hesaplama sınırı aşıldı.');
    return $total;
}
