# NakoPay for Blesta

Payment gateway module for [Blesta](https://www.blesta.com/) billing platform.

## Requirements

- Blesta 5.x
- PHP 8.1+
- NakoPay merchant account with API keys

## Installation

1. Upload the `nakopay` folder to `/components/gateways/nonmerchant/`
2. Go to **Settings > Payment Gateways > Available**
3. Click **Install** next to NakoPay
4. Enter your API key and webhook secret

## Configuration

| Field | Description |
|-------|-------------|
| API Key | Your `sk_live_*` or `sk_test_*` key from nakopay.com/dashboard/api-keys |
| Webhook Secret | HMAC secret for verifying callbacks |
| Test Mode | Toggle between live and test environments |
| Accepted Currencies | Comma-separated list (e.g., BTC,ETH,LTC) |

## Webhook Setup

Set your webhook URL in the NakoPay dashboard to:
```
https://your-blesta-site.com/callback/gw/X/nakopay
```
(where X is the gateway ID assigned by Blesta)

## Links

- [NakoPay Website](https://nakopay.com)
- [Documentation](https://nakopay.com/docs)
- [Integration Guide](https://nakopay.com/integrations/blesta)
- [API Reference](https://nakopay.com/docs/api-reference)

## About Blesta

[Blesta](https://www.blesta.com/) - billing and client management platform for web hosts. Visit their website to learn more about the platform and its features.

## License

MIT
