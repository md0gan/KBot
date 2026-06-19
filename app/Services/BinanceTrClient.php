<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Binance TR (https://www.binance.tr) "Open API" istemcisi.
 *
 * - Imzali uc noktalar: HMAC-SHA256 imza + X-MBX-APIKEY header.
 * - Emir enum'lari: side 0=BUY/1=SELL, type 2=MARKET.
 * - MARKET ALIM "quoteOrderQty" ile yapilir (orn. 10 USDT harca).
 * - MARKET SATIS "quantity" ile yapilir (orn. 0.0005 BTC sat).
 *
 * Referans: config/bot.php ve docs (open/v1/...).
 */
class BinanceTrClient
{
    protected string $apiKey;
    protected string $apiSecret;
    protected string $baseUrl;
    protected string $marketBaseUrl;
    protected int $recvWindow;
    protected int $timeout;
    protected ?int $timeOffset = null;

    public function __construct(
        string $apiKey = '',
        string $apiSecret = '',
        ?string $baseUrl = null,
        ?string $marketBaseUrl = null,
        int $recvWindow = 5000,
    ) {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = rtrim($baseUrl ?: config('bot.base_url'), '/');
        $this->marketBaseUrl = rtrim($marketBaseUrl ?: config('bot.market_base_url'), '/');
        $this->recvWindow = $recvWindow ?: (int) config('bot.recv_window', 5000);
        $this->timeout = (int) config('bot.http_timeout', 15);
    }

    public static function fromSetting(Setting $setting): self
    {
        return new self(
            (string) ($setting->api_key ?? ''),
            (string) ($setting->api_secret ?? ''),
            $setting->effectiveBaseUrl(),
            $setting->effectiveMarketBaseUrl(),
            (int) ($setting->recv_window ?: 5000),
        );
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /* ======================================================================
     | Public (imzasiz) uc noktalar
     * ==================================================================== */

    public function getServerTime(): int
    {
        $j = $this->http()->get($this->baseUrl.'/open/v1/common/time')->json();

        return (int) (data_get($j, 'data.serverTime')
            ?? data_get($j, 'serverTime')
            ?? data_get($j, 'timestamp')
            ?? (int) round(microtime(true) * 1000));
    }

    /**
     * Tum sembolleri ve filtrelerini dondurur (data.list[]).
     */
    public function getSymbols(): array
    {
        $j = $this->http(true)->get($this->baseUrl.'/open/v1/common/symbols')->json();

        return (array) (data_get($j, 'data.list') ?? data_get($j, 'data') ?? []);
    }

    /**
     * Son islem fiyatini dondurur. MAIN (type=1) sembollerde piyasa verisi
     * api.binance.me uzerinden, digerlerinde binance.tr open API'den alinir.
     */
    public function getLastPrice(string $symbol, int $type = 1): float
    {
        $plain = str_replace('_', '', $symbol); // BTC_USDT -> BTCUSDT

        if ($type === 1) {
            // 1) ticker/price
            $price = $this->tryPrice(
                fn () => $this->http(true)->get($this->marketBaseUrl.'/api/v3/ticker/price', ['symbol' => $plain]),
                fn ($j) => data_get($j, 'price')
            );
            if ($price !== null) {
                return $price;
            }

            // 2) son islemler
            $price = $this->tryPrice(
                fn () => $this->http(true)->get($this->marketBaseUrl.'/api/v3/trades', ['symbol' => $plain, 'limit' => 1]),
                fn ($j) => data_get($j, '0.price')
            );
            if ($price !== null) {
                return $price;
            }
        }

        // 3) binance.tr open market trades (type != 1 veya yedek)
        $price = $this->tryPrice(
            fn () => $this->http(true)->get($this->baseUrl.'/open/v1/market/trades', ['symbol' => $symbol, 'limit' => 1]),
            fn ($j) => data_get($j, 'data.list.0.price') ?? data_get($j, 'data.0.price')
        );
        if ($price !== null) {
            return $price;
        }

        throw new BinanceTrException("Fiyat alinamadi: {$symbol}");
    }

    /** @param callable():\Illuminate\Http\Client\Response $request */
    protected function tryPrice(callable $request, callable $extract): ?float
    {
        try {
            $resp = $request();
            if (! $resp->ok()) {
                return null;
            }
            $val = $extract($resp->json());

            return ($val !== null && (float) $val > 0) ? (float) $val : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Kline/mum verisi. MAIN (type=1) sembollerde api.binance.me/api/v1/klines,
     * digerlerinde binance.tr open API denenir. Standart Binance dizisi doner.
     */
    public function getKlines(string $symbol, string $interval = '15m', int $limit = 100, int $type = 1): array
    {
        $plain = str_replace('_', '', $symbol);

        if ($type === 1) {
            try {
                $r = $this->http(true)->get($this->marketBaseUrl.'/api/v1/klines', [
                    'symbol' => $plain, 'interval' => $interval, 'limit' => $limit,
                ]);
                $arr = $r->json();
                if (is_array($arr) && isset($arr[0]) && is_array($arr[0])) {
                    return $arr;
                }
            } catch (\Throwable $e) {
                // yedege gec
            }
        }

        try {
            $r = $this->http(true)->get($this->baseUrl.'/open/v1/market/klines', [
                'symbol' => $symbol, 'interval' => $interval, 'limit' => $limit,
            ]);
            $j = $r->json();
            $list = data_get($j, 'data.list') ?? data_get($j, 'data');
            if (is_array($list)) {
                return $list;
            }
        } catch (\Throwable $e) {
            // bos don
        }

        return [];
    }

    /**
     * High/Low/Close dizileri (ATR vb. icin). Eskiden yeniye sirali.
     *
     * @return array{highs: array<int,float>, lows: array<int,float>, closes: array<int,float>}
     */
    public function getOhlc(string $symbol, string $interval = '1h', int $limit = 100, int $type = 1): array
    {
        $highs = [];
        $lows = [];
        $closes = [];
        foreach ($this->getKlines($symbol, $interval, $limit, $type) as $k) {
            if (is_array($k) && isset($k[2], $k[3], $k[4])) {
                // standart Binance: index 2=high, 3=low, 4=close
                $highs[] = (float) $k[2];
                $lows[] = (float) $k[3];
                $closes[] = (float) $k[4];
            } elseif (is_array($k) && isset($k['high'], $k['low'], $k['close'])) {
                $highs[] = (float) $k['high'];
                $lows[] = (float) $k['low'];
                $closes[] = (float) $k['close'];
            }
        }

        return ['highs' => $highs, 'lows' => $lows, 'closes' => $closes];
    }

    /**
     * Kapanis fiyatlari dizisi (RSI/MA hesaplari icin). Eskiden yeniye sirali.
     *
     * @return array<int,float>
     */
    public function getCloses(string $symbol, string $interval = '15m', int $limit = 100, int $type = 1): array
    {
        $closes = [];
        foreach ($this->getKlines($symbol, $interval, $limit, $type) as $k) {
            if (is_array($k) && isset($k[4])) {
                $closes[] = (float) $k[4];          // standart Binance: index 4 = close
            } elseif (is_array($k) && isset($k['close'])) {
                $closes[] = (float) $k['close'];
            }
        }

        return $closes;
    }

    /* ======================================================================
     | Signed (imzali) uc noktalar
     * ==================================================================== */

    public function getAccount(): array
    {
        $res = $this->signed('GET', '/open/v1/account/spot');

        return (array) data_get($res, 'data', []);
    }

    /**
     * Tek bir varligin serbest/bloke bakiyesini dondurur.
     */
    public function getAssetBalance(string $asset): array
    {
        $account = $this->getAccount();
        foreach (data_get($account, 'accountAssets', []) as $a) {
            if (strtoupper((string) data_get($a, 'asset')) === strtoupper($asset)) {
                return [
                    'asset' => $asset,
                    'free' => (float) data_get($a, 'free', 0),
                    'locked' => (float) data_get($a, 'locked', 0),
                ];
            }
        }

        return ['asset' => $asset, 'free' => 0.0, 'locked' => 0.0];
    }

    /**
     * Yeni emir. $params en az symbol/side/type icermeli.
     * Donus: data { orderId, createTime }.
     */
    public function newOrder(array $params): array
    {
        $res = $this->signed('POST', '/open/v1/orders', $params);

        return (array) data_get($res, 'data', []);
    }

    public function getOrder(int|string $orderId): array
    {
        $res = $this->signed('GET', '/open/v1/orders/detail', ['orderId' => $orderId]);

        return (array) data_get($res, 'data', []);
    }

    public function cancelOrder(int|string $orderId): array
    {
        $res = $this->signed('POST', '/open/v1/orders/cancel', ['orderId' => $orderId]);

        return (array) data_get($res, 'data', []);
    }

    /**
     * MARKET ALIM: belirtilen kote tutar kadar harca (quoteOrderQty).
     * Emri verir ve dolumu (fill) cozumleyip normalize eder.
     */
    public function marketBuyQuote(string $symbol, float $quoteAmount): array
    {
        $data = $this->newOrder([
            'symbol' => $symbol,
            'side' => config('bot.order_side.BUY'),   // 0
            'type' => config('bot.order_type.MARKET'), // 2
            'quoteOrderQty' => $this->trimNumber($quoteAmount),
        ]);

        return $this->resolveFill($data, $symbol);
    }

    /**
     * MARKET SATIS: belirtilen base miktari sat (quantity).
     */
    public function marketSellQuantity(string $symbol, float $quantity): array
    {
        $data = $this->newOrder([
            'symbol' => $symbol,
            'side' => config('bot.order_side.SELL'),   // 1
            'type' => config('bot.order_type.MARKET'), // 2
            'quantity' => $this->trimNumber($quantity),
        ]);

        return $this->resolveFill($data, $symbol);
    }

    /**
     * Emir sonucunu netlestir: dolan miktar, harcanan/elde edilen kote, ort. fiyat.
     */
    public function resolveFill(array $orderData, string $symbol): array
    {
        $orderId = data_get($orderData, 'orderId');
        $detail = $orderData;

        // MARKET emir genelde aninda dolar; detayini birkac kez sorgula.
        if ($orderId) {
            for ($i = 0; $i < 5; $i++) {
                try {
                    $detail = $this->getOrder($orderId);
                } catch (\Throwable $e) {
                    break;
                }
                $status = (int) data_get($detail, 'status', 0);
                if (in_array($status, [2, 3, 5, 6], true)) { // FILLED/CANCELED/REJECTED/EXPIRED
                    break;
                }
                usleep(400_000); // 0.4 sn
            }
        }

        $execQty = (float) data_get($detail, 'executedQty', 0);
        $execQuote = (float) data_get($detail, 'executedQuoteQty', 0);
        $execPrice = (float) data_get($detail, 'executedPrice', 0);

        if ($execPrice <= 0 && $execQty > 0) {
            $execPrice = $execQuote / $execQty;
        }

        return [
            'order_id' => $orderId,
            'status' => $this->statusName((int) data_get($detail, 'status', 0)),
            'status_code' => (int) data_get($detail, 'status', 0),
            'quantity' => $execQty,
            'quote_amount' => $execQuote,
            'price' => $execPrice,
            'raw' => $detail,
        ];
    }

    public function statusName(int $code): string
    {
        return (string) data_get(config('bot.order_status'), $code, (string) $code);
    }

    /**
     * Hesap bilgisini cekerek baglantiyi/anahtarlari dogrular.
     */
    public function testConnection(): array
    {
        $account = $this->getAccount();

        return [
            'ok' => true,
            'can_trade' => (int) data_get($account, 'canTrade', 0) === 1,
            'can_withdraw' => (int) data_get($account, 'canWithdraw', 0) === 1,
            'assets' => count((array) data_get($account, 'accountAssets', [])),
        ];
    }

    /* ======================================================================
     | Altyapi
     * ==================================================================== */

    protected function http(bool $retry = false): PendingRequest
    {
        $req = Http::timeout($this->timeout)
            ->acceptJson()
            ->withHeaders($this->apiKey ? ['X-MBX-APIKEY' => $this->apiKey] : []);

        if ($retry) {
            // Yalnizca baglanti/timeout hatalarinda 3 kez tekrar dene (artan backoff).
            // SADECE idempotent GET okumalarinda kullanilir; emir POST'lari ASLA
            // tekrar denenmez (zaman asimina ugrayan emir borsada dolmus olabilir).
            $req = $req->retry(3, fn (int $attempt) => $attempt * 300, function ($e) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false);
        }

        return $req;
    }

    protected function timestamp(): int
    {
        if ($this->timeOffset === null) {
            try {
                $server = $this->getServerTime();
                $this->timeOffset = $server - (int) round(microtime(true) * 1000);
            } catch (\Throwable $e) {
                $this->timeOffset = 0;
            }
        }

        return (int) round(microtime(true) * 1000) + $this->timeOffset;
    }

    /**
     * Imzali istek gonderir ve {code:0,...} kontrolu yapar.
     */
    protected function signed(string $method, string $path, array $params = []): array
    {
        if (! $this->hasCredentials()) {
            throw new BinanceTrException('API anahtari/secret tanimli degil. Lutfen Ayarlar bolumunden girin.');
        }

        $params['recvWindow'] = $this->recvWindow;
        $params['timestamp'] = $this->timestamp();

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $query, $this->apiSecret);
        $payload = $query.'&signature='.$signature;

        $url = $this->baseUrl.$path;

        if (strtoupper($method) === 'GET') {
            $response = $this->http(true)->get($url.'?'.$payload);
        } else {
            $response = $this->http()
                ->withBody($payload, 'application/x-www-form-urlencoded')
                ->post($url);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new BinanceTrException('Beklenmeyen API yaniti (HTTP '.$response->status().').');
        }

        $code = (int) data_get($json, 'code', 0);
        if ($code !== 0) {
            $msg = (string) (data_get($json, 'msg') ?? data_get($json, 'message') ?? 'Bilinmeyen hata');
            throw new BinanceTrException("Binance TR hata (code {$code}): {$msg}", $code);
        }

        return $json;
    }

    /**
     * Sayiyi API'ye uygun, gereksiz sifirlardan arindirilmis string'e cevirir.
     */
    protected function trimNumber(float $n): string
    {
        $s = rtrim(rtrim(sprintf('%.8f', $n), '0'), '.');

        return $s === '' ? '0' : $s;
    }
}
