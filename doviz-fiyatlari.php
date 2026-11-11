<?php
declare(strict_types=1);

function dovizNumber(string $value): ?float {
    $value = trim(str_replace(["\xc2\xa0", ' ', '₺'], '', $value));
    $value = preg_replace('/[^0-9,.-]/u', '', $value);
    if ($value === '' || !preg_match('/^-?[\d.]+(?:,\d+)?$/', $value)) return null;
    return (float)str_replace(',', '.', str_replace('.', '', $value));
}

function fetchDovizText(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8'],
        CURLOPT_REFERER => 'https://www.doviz.com/',
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => __DIR__ . '/certs/cacert.pem',
    ]);
    $html = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($html === false || $status !== 200) throw new RuntimeException('Döviz.com verisi alınamadı (HTTP ' . $status . '). ' . $error);
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return preg_replace('/\s+/u', ' ', trim($dom->textContent)) ?: '';
}

function fetchDovizPrices(): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL eklentisi gerekli.');
    $goldText = fetchDovizText('https://altin.doviz.com/gram-altin');
    $silverText = fetchDovizText('https://altin.doviz.com/gumus');
    $usdText = fetchDovizText('https://kur.doviz.com/serbest-piyasa/amerikan-dolari');
    $fuelText = fetchDovizText('https://www.doviz.com/akaryakit-fiyatlari/istanbul-avrupa');
    preg_match('/Son\s*\(\d{2}:\d{2}\)\s*([\d.]+,\d{2})/u', $goldText, $goldMatch);
    preg_match('/Son\s*\(\d{2}:\d{2}\)\s*([\d.]+,\d{2})/u', $silverText, $silverMatch);
    preg_match('/Alış\s*\/\s*Satış\s*([\d.]+,\d{4})\s*\/\s*([\d.]+,\d{4})/u', $usdText, $usdMatch);
    preg_match('/ortalama\s+benzin\s+fiyatı\s+([\d.]+,\d{2})\s+lira,\s+motorin\s+fiyatı\s+([\d.]+,\d{2})\s+lira/iu', $fuelText, $fuelMatch);
    $prices = [
        'gold' => isset($goldMatch[1]) ? dovizNumber($goldMatch[1]) : null,
        'silver' => isset($silverMatch[1]) ? dovizNumber($silverMatch[1]) : null,
        'usd' => ['buy' => isset($usdMatch[1]) ? dovizNumber($usdMatch[1]) : null, 'sell' => isset($usdMatch[2]) ? dovizNumber($usdMatch[2]) : null],
        'gasoline' => isset($fuelMatch[1]) ? dovizNumber($fuelMatch[1]) : null,
        'diesel' => isset($fuelMatch[2]) ? dovizNumber($fuelMatch[2]) : null,
    ];
    if ($prices['gold'] === null || $prices['silver'] === null || $prices['usd']['buy'] === null || $prices['usd']['sell'] === null || $prices['gasoline'] === null || $prices['diesel'] === null) throw new RuntimeException('Döviz.com sayfasındaki fiyat formatı tanınamadı.');
    return ['prices' => $prices, 'fuelLocation' => 'İstanbul Avrupa', 'updatedAt' => date('d.m.Y H:i')];
}
