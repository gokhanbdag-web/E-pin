<?php
declare(strict_types=1);

const PAYKWIK_Tcmb_URL = 'https://www.tcmb.gov.tr/kurlar/today.xml';

function paykwik_calculate_price(float $eurAmount, float $rate, float $marginPercent): float {
    if (!is_finite($eurAmount) || !is_finite($rate) || !is_finite($marginPercent) || $eurAmount <= 0 || $rate <= 0 || $marginPercent < 0 || $marginPercent > 500) {
        throw new InvalidArgumentException('Paykwik fiyatlandırma değerleri geçersiz.');
    }
    return round($eurAmount * $rate * (1 + $marginPercent / 100), 2, PHP_ROUND_HALF_UP);
}

function paykwik_fetch_tcmb_rate(): array {
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init(PAYKWIK_Tcmb_URL);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'E-pin Paykwik pricing']);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status !== 200) throw new RuntimeException('TCMB kur servisine şu anda ulaşılamıyor.');
    } else {
        $context = stream_context_create(['http' => ['timeout' => 8, 'follow_location' => 0, 'header' => "User-Agent: E-pin Paykwik pricing\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents(PAYKWIK_Tcmb_URL, false, $context);
        if (!is_string($body) || $body === '') throw new RuntimeException('TCMB kur servisine şu anda ulaşılamıyor.');
    }
    if (strlen($body) > 1048576) throw new RuntimeException('TCMB kur yanıtı beklenen boyutu aşıyor.');
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$xml) throw new RuntimeException('TCMB kur yanıtı okunamadı.');
    $currency = $xml->xpath('/Tarih_Date/Currency[@CurrencyCode="EUR"]');
    $raw = isset($currency[0]->ForexSelling) ? trim((string) $currency[0]->ForexSelling) : '';
    $rate = is_numeric($raw) ? (float) $raw : 0.0;
    $dateRaw = (string) ($xml['Tarih'] ?? '');
    $rateDate = DateTimeImmutable::createFromFormat('d.m.Y', $dateRaw);
    if ($rate <= 0 || !$rateDate) throw new RuntimeException('TCMB Euro satış kuru yanıt içinde bulunamadı.');
    return ['currency' => 'EUR', 'rate' => $rate, 'rate_type' => 'ForexSelling', 'rate_date' => $rateDate->format('Y-m-d'), 'fetched_at' => date('Y-m-d H:i:s'), 'source_url' => PAYKWIK_Tcmb_URL];
}

function paykwik_store_rate(PDO $pdo, array $rate): void {
    $q = $pdo->prepare('INSERT INTO paykwik_exchange_rates(currency,rate,rate_type,rate_date,fetched_at,source_url) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate),rate_type=VALUES(rate_type),fetched_at=VALUES(fetched_at),source_url=VALUES(source_url)');
    $q->execute([$rate['currency'], number_format((float) $rate['rate'], 6, '.', ''), $rate['rate_type'], $rate['rate_date'], $rate['fetched_at'], $rate['source_url']]);
}

function paykwik_latest_rate(PDO $pdo, bool $refreshIfNewDay = true): ?array {
    $q = $pdo->query("SELECT currency,rate,rate_type,rate_date,fetched_at,source_url FROM paykwik_exchange_rates WHERE currency='EUR' ORDER BY rate_date DESC LIMIT 1");
    $cached = $q->fetch() ?: null;
    if ($refreshIfNewDay && (!$cached || $cached['rate_date'] !== date('Y-m-d'))) {
        try {
            $fresh = paykwik_fetch_tcmb_rate();
            paykwik_store_rate($pdo, $fresh);
            return $fresh;
        } catch (Throwable $e) {
            if (!$cached) throw $e;
            $cached['stale'] = true;
        }
    }
    return $cached;
}

function paykwik_refresh_product_prices(PDO $pdo, array $rate): int {
    $q = $pdo->query('SELECT id,paykwik_eur_amount,paykwik_margin_percent FROM products WHERE paykwik_enabled=1 AND paykwik_eur_amount IS NOT NULL');
    $update = $pdo->prepare('UPDATE products SET price=? WHERE id=? AND paykwik_enabled=1');
    $count = 0;
    foreach ($q->fetchAll() as $product) {
        $price = paykwik_calculate_price((float) $product['paykwik_eur_amount'], (float) $rate['rate'], (float) $product['paykwik_margin_percent']);
        $update->execute([number_format($price, 2, '.', ''), (int) $product['id']]);
        $count += $update->rowCount() > 0 ? 1 : 0;
    }
    return $count;
}
