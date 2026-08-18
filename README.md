# mulps/shipstation-live-rates

Magento Open Source **2.4.9** shipping carrier (`Mulps_ShipStationLiveRates`). Live quotes from ShipStation API v2, padded by a global or ZIP3 markup into a **single** shipping fee. If live rates fail or time out, checkout reads a **local** heuristic snapshot built from historical labels — checkout never pages ShipStation history.

## Names

| Layer | Name |
|---|---|
| Composer | `mulps/shipstation-live-rates` |
| Magento module | `Mulps_ShipStationLiveRates` |
| Carrier code | `ssliverates` |
| Staging path | `app/code/Mulps/ShipStationLiveRates` |
| Production path | `vendor/mulps/shipstation-live-rates` |

PHP `~8.4.0 \|\| ~8.5.0`. Do not put API keys in this repo.

## Staging (git clone)

From the Magento root, developer mode:

```bash
mkdir -p app/code/Mulps
git clone <this-repo> app/code/Mulps/ShipStationLiveRates
bin/magento module:enable Mulps_ShipStationLiveRates
bin/magento setup:upgrade
bin/magento cache:flush
```

Configure **Stores → Configuration → Sales → Shipping Methods → ShipStation Live Rates**: API key, carrier IDs, origin postal code, global markup %, optional regional JSON `{"100":12,"850":8}`.

After `git pull` in the module folder: `setup:upgrade` if `etc/` changed, then `cache:flush`.

## Production (Composer)

Do not leave a copy in `app/code` on the same instance.

```bash
composer require mulps/shipstation-live-rates
bin/magento module:enable Mulps_ShipStationLiveRates
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Until Packagist, add a VCS repository in the Magento root `composer.json` pointing at this GitHub repo.

## Behaviour

1. `collectRates` checks Magento cache for a raw live quote (short TTL).
2. On miss, `POST /v2/rates` with a 2–3s timeout. Raw amount is cached; markup is applied after the cache read.
3. USPS-family success is logged from `rate_response` (not Magento’s USPS module).
4. On failure, read `mulps_sslr_heuristic` (Redis-backed after first read).
5. Nightly cron `mulps_sslr_rebuild_heuristic` GETs `/v2/labels`, writes ZIP3 × weight-bucket × service cells (p75 + neighbor merge).
