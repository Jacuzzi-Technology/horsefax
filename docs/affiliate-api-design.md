# Horsefax Partner Data API: design (DRAFT, Oct 18 2026)

Status: **design only, for Richard's approval. Nothing is built.** Companion documents are in
`Jacuzzi Technology/Working Documents/Site protection/`: the bot check plan and the no-scraping terms.

## 1. Purpose

Licensed partners get Horsefax data through an API under a written agreement, instead of scraping horsefax.com. Partners include marketplaces, auctions, associations and registries. Trusted Horse is one customer like any other, with no special access (see the verification-brands decision).

Goals:
- One controlled, logged path for bulk or programmatic access.
- Never expose more than the public site plus what the partner's agreement allows.
- Never pass on data that Horsefax isn't allowed to pass on (see section 6).

## 2. Shape

- **Host:** `https://api.horsefax.com/v1/...`. It's a separate hostname with the Cloudflare proxy off, so the website's bot check never blocks partners. It's served by the same Laravel app (a separate route file, `routes/api.php`, and its own nginx `server` block). JSON only.
- **Versioning:** `/v1/` in the path. Breaking changes get `/v2/`. A version stays available for at least 6 months after its replacement launches. TODO: the agreement terms must match.
- **Auth:** an API key per partner *application*, sent as `Authorization: Bearer hfx_live_…`.
  - Keys are stored as SHA-256 hashes, and only their last 4 characters are shown again.
  - Each key has a name, scopes, a rate tier, optional IP allowlist (CIDRs), expiry date and revocation.
  - `hfx_test_…` keys reach a sandbox that holds SAMPLE horses only.
  - Not Sanctum personal tokens: partners are organizations, not users. A small custom guard is simpler, and keeps the website's login separate.
- **Partner self-service:** none at first. Staff create partners and keys in /admin after the agreement is signed, and the key is shown once.

## 3. Data model (new tables)

| Table | Key columns |
|-------|-------------|
| `partners` | name, kind (marketplace/association/registry/other), contact email, status (active/suspended/ended), agreement reference, agreement signed date, agreement ends date, notes |
| `partner_keys` | partner_id, name, key_hash, last4, environment (live/test), scopes (json), rate_tier, allowed_ips (json, nullable), expires_at, revoked_at, last_used_at |
| `api_requests` (audit log) | key_id, partner_id, method, path, query (with sensitive params stripped), status, response_ms, record_count, horse_ids returned (array; capped), IP, user agent, created_at |
| `partner_source_grants` (optional) | partner_id, data_source_id: limits a partner to sources its agreement covers |

The audit log is append-only from the app. Rows are kept 24 months (TODO: match the agreement and the privacy policy). They are partitioned by month, so 4M+ horse lookups don't slow it down.

## 4. Scopes

| Scope | Gives |
|-------|-------|
| `horses:search` | name or identifier search, returning a list of matches (Horsefax ID, name, foal year, sex, breed, registry), capped at 25 per page |
| `horses:read` | a horse's public identity summary: the same fields as the public summary, with each fact's source type and verification status |
| `pedigree:read` | sire, dam and up to 3 generations (or what we hold) |
| `results:read` | show and competition results already shown publicly |
| `sales:read` | sale history, **without prices** unless the owner has made prices public (prices are private by default) |
| `verification:read` | Horsefax Verified status and detail for a fact (method, scope, date); team labels only, never staff names |
| `reports:create` | generate an owner-authorized report (later; needs the owner-consent flow and payments) |

Never available by API, at any scope:
- private owner uploads, medical records and documents (unless an owner shares them for that partner, a later feature)
- previous owners' names
- contributor or staff identities
- unlisted horses (the owner turned off listing)
- raw harvested evidence copies

## 5. Endpoints (v1)

```
GET /v1/horses/search?name=&registry=&reg_number=&foal_year=&sex=&page=
GET /v1/horses/{horsefax_id}
GET /v1/horses/{horsefax_id}/pedigree?generations=3
GET /v1/horses/{horsefax_id}/results?page=
GET /v1/horses/{horsefax_id}/sales?page=
GET /v1/horses/{horsefax_id}/verifications
GET /v1/lookup?registry=AQHA&reg_number=…           (exact identifier match → horsefax_id)
GET /v1/me                                           (key's partner, scopes, tier, usage today)
```

- **No "list all horses" or bulk-export endpoint**, and no paging through the whole database. Search needs a name of at least 3 letters or an identifier, and follows the indexed-lookup rules for the 4M-row horses table.
- If a partner's agreement covers bulk delivery, that is a separate, signed dataset export run by staff, not an endpoint.
- Every fact carries `source_type`, `status` and `source` (the data source key and name). A required attribution string is also included where the source licence requires it, for example the IFCE open licence. Records from the IFCE are marked as such.
- Errors use the JSON `{error:{code,message}}` format. A horse the key may not see returns 404, never 403, so a partner can't use errors to find out which horses exist.

## 6. Redistribution guard (important)

Horsefax collected some data from public pages whose terms are unclear (`data_sources.permission` = `no_terms_posted`). Showing a fact on our own site is not the same as **licensing it to a third party**.

Proposal: add `data_sources.api_redistribution` with the values `allowed`, `attribution_required`, `not_allowed` and `unknown`, defaulting to `unknown`. The API returns a fact only when its source is `allowed` or `attribution_required`. Owner-reported and Horsefax-verified facts are allowed by default; TODO: confirm with an attorney.

**TODO for Richard and an attorney:** set this for each source before any partner goes live, for example IFCE, NRHA, NRCHA, Congress, the state QHAs and the sale sites.

## 7. Rate limits and quotas

- Laravel `RateLimiter`, keyed by API key (not IP).
- Each response returns `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `Retry-After`.
- Daily quotas are counted from `api_requests` and cached in Redis/DB. Tiers are set in config:

| Tier | Per minute | Per day | For |
|------|-----------|---------|-----|
| sandbox | 30 | 1,000 | test keys |
| standard | 60 | 10,000 | most partners |
| high | 300 | 100,000 | large marketplaces, by agreement |

Pricing and tier names: TODO (Richard). Numbers are placeholders.

- nginx on api.horsefax.com adds a coarse per-IP `limit_req`, about 20 requests per second, as a backstop before PHP.

## 8. Abuse detection and watermarking

- Daily report to staff: keys near their quota, unusual record counts, or searches that walk the alphabet ("aa", "ab", …) or the registration-number space.
- Optional per-partner *canary* records: harmless fake horses, unique to each partner, that the public site never shows. If one turns up elsewhere, we know whose feed leaked it. Must be disclosed in the agreement. TODO: Richard decides.
- One-click key revocation and partner suspension in /admin. Takes effect immediately, with the key's cache cleared.

## 9. Security

- HTTPS only, HSTS.
- Keys are never logged; the audit log stores only the key ID.
- CORS is off: server-to-server only, so no key can be exposed in a browser.
- Optional IP allowlists per key.
- `trustProxies` limited to known proxies before launch (the current `'*'` lets clients spoof IPs; see the bot check plan, 4C).
- Tests: auth, scopes, 404-not-403, the redistribution guard, the rate limits, and that the audit log records every request.

## 10. Written agreement (outside the code)

The data license agreement is drafted by an attorney (TODO). It should cover:
- permitted use
- no resale or sublicensing
- no AI training, unless agreed
- attribution, display rules and caching limits (for example, refresh or delete within N days)
- security duties and breach notice
- audit rights, fees and termination with deletion of the data
- the canary disclosure, if used

The API enforces what it can; the agreement covers the rest.

## 11. Build plan (after approval)

1. Migrations, models and admin screens for partners and keys.
2. API guard, scopes, rate limiter and audit logging.
3. v1 read endpoints plus the redistribution guard, with tests.
4. Sandbox data, `/v1/me`, and a short partner doc page.
5. nginx `server` block and certificate for api.horsefax.com, plus a Cloudflare DNS-only record. Coordinated with the other Horsefax session before deploying.

Estimate: about 2–3 working sessions for steps 1–4.

## 12. Questions for Richard

1. Approve the separate `api.horsefax.com` host and key-per-application auth?
2. Approve the scope list, and that prices stay out unless the owner made them public?
3. Approve the redistribution guard, which means some harvested facts are never passed to partners?
4. Canary records: yes or no?
5. Who is the first partner, so v1 can be shaped around a real need?
