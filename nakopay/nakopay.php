<?php
/**
 * NakoPay Gateway for Blesta 5.x
 *
 * Non-merchant gateway that redirects customers to the NakoPay hosted
 * checkout page and processes webhook callbacks for payment confirmation.
 *
 * @package blesta
 * @subpackage blesta.components.gateways.nonmerchant.nakopay
 * @license MIT
 */
class Nakopay extends NonmerchantGateway
{
    /**
     * @var string Plugin version
     */
    private static $version = '1.0.0';

    /**
     * @var string API base URL (Supabase edge functions)
     */
    private static $apiBase = 'https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1';

    /**
     * @var string Fallback API base URL
     */
    private static $apiBaseFallback = 'https://api.nakopay.com/v1';

    /**
     * @var array Supported currencies
     */
    private static $supportedCurrencies = ['USD', 'EUR', 'GBP', 'BTC', 'ETH', 'LTC', 'XMR'];

    /**
     * Construct
     */
    public function __construct()
    {
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
        Language::loadLang('nakopay', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Returns the name of this gateway
     */
    public function getName()
    {
        return Language::_('Nakopay.name', true);
    }

    /**
     * Returns the version of this gateway
     */
    public function getVersion()
    {
        return self::$version;
    }

    /**
     * Returns the authors of this gateway
     */
    public function getAuthors()
    {
        return [['name' => 'NakoPay', 'url' => 'https://nakopay.com']];
    }

    /**
     * Returns supported currencies
     */
    public function getCurrencies()
    {
        return self::$supportedCurrencies;
    }

    /**
     * Sets the currency code to be used for all subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Returns all fields to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['api_key', 'webhook_secret'];
    }

    /**
     * Sets the meta data for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Returns gateway settings input fields
     */
    public function getSettings(array $meta = null)
    {
        $this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);
        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data
     */
    public function editSettings(array $meta)
    {
        $rules = [
            'api_key' => [
                'valid' => [
                    'rule' => ['matches', '/^sk_(live|test)_.+$/'],
                    'message' => Language::_('Nakopay.!error.api_key.valid', true),
                ],
            ],
        ];

        $this->Input->setRules($rules);
        if ($this->Input->validates($meta)) {
            return $meta;
        }
        return null;
    }

    /**
     * Create and return the view content required to modify the settings
     * of this gateway
     */
    public function buildProcess(array $contactInfo, $amount, array $invoiceAmounts = null, array $options = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);

        $apiKey = $this->meta['api_key'] ?? '';
        $testMode = ($this->meta['test_mode'] ?? 'false') === 'true';

        // Build callback URL
        $callbackUrl = Configure::get('Blesta.gw_callback_url')
            . Configure::get('Blesta.company_id') . '/nakopay/';

        // Create invoice via NakoPay API
        $invoiceData = [
            'amount' => (float) $amount,
            'currency' => $this->currency ?? 'USD',
            'description' => $options['description'] ?? 'Blesta Invoice',
            'metadata' => [
                'client_id' => $contactInfo['id'] ?? null,
                'invoices' => $invoiceAmounts,
            ],
            'redirect_url' => $options['return_url'] ?? '',
            'webhook_url' => $callbackUrl,
        ];

        $result = $this->apiRequest('POST', '/payment-links', $invoiceData);

        if (isset($result['error'])) {
            $this->Input->setErrors([
                'api' => ['error' => $result['error']['message'] ?? 'Failed to create invoice'],
            ]);
            return null;
        }

        $checkoutUrl = $result['url'] ?? $result['checkout_url'] ?? $result['hosted_url'] ?? '';

        // Return redirect form
        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));
        $this->view->set('checkout_url', $checkoutUrl);
        $this->view->set('invoice_id', $result['id'] ?? '');

        return $this->view->fetch();
    }

    /**
     * Validates the incoming POST/GET response from the gateway
     */
    public function validate(array $get, array $post)
    {
        // Read raw body for signature verification
        $rawBody = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_X_NAKOPAY_SIGNATURE'] ?? '';
        $secret = $this->meta['webhook_secret'] ?? '';

        if ($secret && !$this->verifySignature($rawBody, $signature, $secret)) {
            $this->Input->setErrors([
                'webhook' => ['signature' => Language::_('Nakopay.!error.webhook.signature', true)],
            ]);
            return false;
        }

        $payload = json_decode($rawBody, true);
        $event = $payload['event'] ?? $payload['type'] ?? '';
        $data = $payload['data'] ?? $payload;

        return [
            'event' => $event,
            'invoice_id' => $data['id'] ?? $data['invoice_id'] ?? '',
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? '',
            'status' => $data['status'] ?? '',
            'metadata' => $data['metadata'] ?? [],
        ];
    }

    /**
     * Returns the transaction result from the gateway
     */
    public function success(array $get, array $post)
    {
        $data = $this->validate($get, $post);
        if ($this->Input->errors()) {
            return;
        }

        $event = $data['event'];
        $status = 'error';

        if (in_array($event, ['invoice.paid', 'invoice.confirmed'])) {
            $status = 'approved';
        } elseif ($event === 'invoice.expired') {
            $status = 'declined';
        }

        return [
            'client_id' => $data['metadata']['client_id'] ?? null,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'invoices' => $data['metadata']['invoices'] ?? null,
            'status' => $status,
            'reference_id' => null,
            'transaction_id' => $data['invoice_id'],
        ];
    }

    /**
     * Make an API request to NakoPay
     */
    private function apiRequest(string $method, string $path, array $data = null): array
    {
        $url = self::$apiBase . $path;
        $headers = [
            'Authorization: Bearer ' . ($this->meta['api_key'] ?? ''),
            'Content-Type: application/json',
            'User-Agent: nakopay-blesta/' . self::$version,
            'X-NakoPay-Version: 2025-04-20',
        ];

        if ($method === 'POST') {
            $headers[] = 'Idempotency-Key: idem_' . bin2hex(random_bytes(16));
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => ['message' => 'Connection failed: ' . $error]];
        }

        $result = json_decode($response, true) ?? [];

        if ($httpCode >= 400) {
            return ['error' => ['message' => $result['message'] ?? "HTTP {$httpCode}", 'status' => $httpCode]];
        }

        return $result;
    }

    /**
     * Verify webhook HMAC-SHA256 signature
     */
    private function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}
