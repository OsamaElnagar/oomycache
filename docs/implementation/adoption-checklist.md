# Adoption checklist

Use this before adding a model to an application, and keep it with the model's tests. The cache is
opt-in; a model that fails a gate must not be added to the allowlist.

## 1. Model scope

- [ ] The model is read-heavy and not a write hotspot.
- [ ] Reads are repeated across requests/pages, or are expensive enough to justify the store read.
- [ ] The model is not a ledger/financial/audit source unless the team explicitly accepts it.
- [ ] The model's writes are understood: Eloquent events, builder mass writes, transactions,
      quiet writes, raw SQL, pivot writes, imports and job writes.
- [ ] A preset/options choice is recorded: TTL, aggregates, pagination, negative caching and
      dependency classes.

## 2. Integration

- [ ] Add `HasQueryCache` only. Do not add global base-model traits.
- [ ] Use the shared reference preset; avoid per-query `cacheFor()` until the default is proven.
- [ ] Add dependency classes only when the dependency model itself is cacheable; otherwise doctor
      warns and the dependency will not invalidate.
- [ ] For raw report reads, use `Oomycache::rememberQuery()` with an explicit table list and a stable
      caller-owned key.
- [ ] For raw/quiet writes or raw pivot tables, add explicit `flush()`/`flushTables()` at the write
      site.

## 3. Correctness tests

- [ ] Warm read executes 0 application queries for the table.
- [ ] Update/delete/restore/force-delete makes the next read miss and return fresh data.
- [ ] Eager loads/`withCount`/`whereHas`/join dependencies invalidate on related writes.
- [ ] `belongsToMany`/`morphToMany` attach/detach/sync invalidate both sides.
- [ ] Transactions: reads inside a transaction bypass; writes invalidate after commit.
- [ ] Raw/negative/empty-result behavior is explicitly accepted or disabled.
- [ ] Audit/stats show one invalidation per logical write chain.
- [ ] Tenant keys fail closed if tenancy is active without a tenant.

## 4. Measurement

- [ ] Baseline query counts on the real pages/API routes before adoption.
- [ ] After adoption: same flows, compare `app_queries` and total statements; not just latency.
- [ ] Run `oomycache:bench` on the host/store the app will use; record app/store/total counts.
- [ ] Confirm no search-term query is cached unless deliberately enabled and protected.
- [ ] Check the cache store's own cost: database store adds statements; file/Redis/Memcached do not.

## 5. Rollout

- [ ] Add one small batch (1–3 models), not the whole app.
- [ ] Keep an exact allowlist and an explicit deny list.
- [ ] Deploy behind `OOMYCACHE_ENABLED=false` first, then enable with a config change.
- [ ] Rollback is one env flag: `OOMYCACHE_ENABLED=false` + config rebuild + worker restart.
- [ ] Schedule file-store cleanup if using `file`; keep database-store `prune` if using `database`.
- [ ] Stop after the batch and review counts, audit noise and stale-read reports.

## 6. What not to do

- Do not adopt cacheable models because “they are small”; the store read may cost more than the query.
- Do not use `cacheForForever()` on the database store.
- Do not use `Cache::flush()` for application invalidation.
- Do not cache user-controlled, high-cardinality search filters without a deliberate protection plan.
- Do not skip the write-path audit because “the model does not change often”.

See the [production runbook](production-runbook.md) for deployment, store selection and rollback.
