# 04 — Filament 5 / Livewire 4 Compatibility & Integration Plan

> **Baseline (verified locally, 2026-11-09):** Laravel **13.40.0**, Filament **5.11.2** (`filament/filament`, `tables`, `actions`, `forms`, `widgets`, `support`), Livewire **4.4.8**, PHP 8.5 runtime. All `path:line` citations are against `D:\Herd\filament-custom-pages\vendor\` at those versions. Claims that only runtime/POC can settle are marked **⚠ unverified — validate in POC**. This document is the evidence source referenced by `01` §5, `03` §2.3/§10/§11/§16 and `05` §4.
>
> **Scope correction vs. skeleton:** `filament/panels` no longer exists as a separate Composer package in v5 — panel classes ship inside `filament/filament/src/Panel.php`. `orchestra/testbench`/`pest` are package dev deps, not installed in this app.

## 0. Executive summary + hard requirements

1. **Filament has no query layer of its own.** Resources, tables, widgets, relation managers and global search all reduce to Eloquent calls on `Model::query()` or a relation's query. `Resource::getEloquentQuery()` is just `static::getModel()::query()` (`vendor/filament/filament/src/Resources/Resource.php:92-110`); `ListRecords` hands that builder to the table (`Resources/Pages/ListRecords.php:127-137,242-245`); relation-manager tables use `$relationship->getQuery()` (`Resources/Concerns/InteractsWithRelationshipTable.php:67-70,122-129`); global search uses `getEloquentQuery()` again (`Resources/Resource/Concerns/HasGlobalSearch.php:261-264`). Therefore the custom-Eloquent-builder interception chosen in `03` (ADR-1) is **sufficient for every Filament read path, today and for future Filament features**, with zero resource edits.
2. **Livewire 4 re-renders the whole component on every request.** `mount` → render → dehydrate/snapshot (`vendor/livewire/livewire/src/Mechanisms/HandleComponents/HandleComponents.php:36-85`); every update → hydrate → call methods → **render** → dehydrate (`:185-245`). Filament's own caches are request-scoped `protected` properties only — `$cachedTableRecords` (`tables/src/Concerns/HasRecords.php:27,185`), `$cachedStats` (`widgets/src/StatsOverviewWidget.php:23,97-112`), `$cachedData` (`widgets/src/ChartWidget.php:25,68-71`). Nothing survives the request. **Every table page view, filter/sort/page change, search keystroke, action, poll and lazy-widget load re-executes its SQL.** This is the entire product upside for the ERP.
3. **Filament's normal writes do fire model events; mass paths do not.** Bulk actions fetch records by default (`actions/src/Concerns/CanFetchSelectedRecords.php:9-23` → per-record `delete()`/`forceDelete()`/`restore()` in `DeleteBulkAction.php:81-118`, `ForceDeleteBulkAction.php:81-118`, `RestoreBulkAction.php:81-119`), so core `eloquent.*` listeners see them. Gaps: `->fetchSelectedRecords(false)` mass branches (`DeleteBulkAction.php:83-95` `getSelectedRecordsQuery()->delete()`, `RestoreBulkAction.php:83-95` `->restore()`, `DissociateBulkAction.php:49-66` `->update()`), table reorder (`tables/src/Concerns/CanReorderRecords.php:24-68`), pivot attach/detach (`actions/src/AttachAction.php:116`, `DetachAction.php:48-51`; Laravel pivot insert/delete never fire model events — `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Relations/Concerns/InteractsWithPivotTable.php:333-355,519-550`), raw `DB` writes, and any query built from a model that is not opted in.
4. **Invalidation must be synchronous, before Livewire renders.** The write happens inside `callMethods()`, the render happens immediately afterwards in the same request (`HandleComponents.php:219-241`); Filament actions dispatch `ActionCalled` synchronously when the action closure returns (`actions/src/Concerns/HasLifecycleHooks.php:66-80`; `InteractsWithActions.php:444-482`). A queued/after-response flush would render stale data in the very response that performed the write. Core model events + builder write overrides + bridge `ActionCalled` flush must all complete before `render()`.
5. **Filament's default paginator COUNT.** Filament's default table pagination always calls `$query->toBase()->getCountForPagination()` and passes the total into `paginate()` (`tables/src/Concerns/CanPaginateRecords.php:41-71`; Laravel `Eloquent/Builder.php:1177-1193`). `toBase()` deliberately returns the plain `Query\Builder` (`Eloquent/Builder.php:2095-2097`), so a naive builder subclass never sees that count. **Resolution (P0, architecture §2.1.1):** the core trait also returns a `QueryCacheQueryBuilder` from `newBaseQueryBuilder()`, intercepting `getCountForPagination()` and recording join/subquery tables; warm table renders therefore lose the COUNT too. Residual uncached paths are listed in architecture §2.3. See §2.2, §6.4, §10.

### Hard requirements (numbered, testable)

- **HR-1 Zero-resource-change read compatibility.** A model using the opt-in trait must make Filament Resources/Tables/RelationManagers/Widgets/global search work with unchanged return types (`Eloquent\Collection`, `LengthAwarePaginator`, `CursorPaginator`, `Model|null`, scalars) and unchanged paginator metadata (`total`, `perPage`, `currentPage`, `path`, `pageName`).
- **HR-2 Opt-in only.** No trait → no builder subclass → Filament untouched. Bridge never patches Filament files, never uses `toBase()` writes, and core never imports `Filament\*`/`Livewire\*` (ADR-8).
- **HR-3 Tenant/panel isolation is fail-closed.** Keys embed tenant class+key and panel id when Filament is present; if the current panel has tenancy but no tenant is resolved **and** no explicit non-Filament `TenantResolver` is configured, reads bypass the cache (never cache under `none`). Panel id is always part of the key.
- **HR-4 Invalidation is synchronous and complete before render.** Model events + builder write overrides (mass update/delete/restore/increment/decrement/upsert) + bridge flush; per-request memo is purged by the same invalidation path (see §6.5).
- **HR-5 Search-term queries are bypassed by default** (ADR-4): Filament table/column search and topbar global search are high-cardinality; cache only aggregate/pagination/filter/sort shapes unless explicitly opted in. Relationship `Select` option lookups are cacheable (bounded ID sets) — see §2.5.
- **HR-6 Transaction safety.** Reads inside a transaction bypass cache; invalidations fired inside a write transaction are deferred via the writing connection's `afterCommit` (ADR-5). Filament actions may wrap closures in a DB transaction (`InteractsWithActions.php:417-482`).
- **HR-7 No double caching / no leaking computed caches.** The bridge must not wrap engines in `#[Computed(cache:true)]` (no tenant/user key — §3.3), and must respect Filament's own per-request caches without serialising them.
- **HR-8 Bridge is optional, version-gated, and permission-gated.** Auto-detects Filament 5 + Livewire 4; refuses to boot (clear log) on incompatible versions; every purge/warm UI action checks an authorization callback/gate.
- **HR-9 Theme-agnostic UI.** Bridge pages/widgets use only Filament Blade components (`x-filament::*`) and Filament tables/schemas — no custom CSS assets, no assumptions about theme tokens — so they render under the app's `filament-liquid-glass` theme plugin (app has 8 panels, each registering `LiquidGlassPlugin`).
- **HR-10 Cache-store correctness > hit rate.** A read failure falls back to the DB; a failed invalidation is a loud incident (ADR-7). No cache entries may outlive their invalidation version token.

## 1. Verified environment

| Component | Installed | Declared constraint (source) | Notes |
|---|---|---|---|
| PHP | 8.5 runtime (app AGENTS) | `php: ^8.2` (`filament/filament/composer.json`) / package `^8.3` | bridge code targets 8.3+ |
| Laravel | 13.40.0 | `illuminate/contracts: ^11.28\|^12.0\|^13.0` (`filament/support/composer.json`); app `laravel/framework: ^13.17` | package `illuminate/support ^12\|^13` is narrower than Filament's — compatible |
| Filament | 5.11.2 | app `filament/filament: ^5.7` | v5 ships panels inside `filament/filament` |
| Livewire | 4.4.8 | `livewire/livewire: ^4.4.8` (`filament/support/composer.json`); app `^4.1` | **Filament 5 requires Livewire 4.4.8+** |
| Filament packages | `filament/tables`, `actions`, `forms`, `widgets`, `schemas`, `infolists`, `notifications`, `support`, `query-builder` all 5.11.2 | — | no `filament/panels` package |
| Test stack (package) | not installed in app | `orchestra/testbench ^10\|^11`, `pestphp/pest ^4.6\|^5.0` (`packages/OoMyCache/composer.json:30-32`) | Filament E2E is a separate dev-dependency lane (`05` §2) |

**Runtime facts that shape the design:** the app currently declares **8 panels** (`app/Providers/Filament/*PanelProvider.php`), each with a `LiquidGlassPlugin`; a custom theme (`resources/css/app.css`) consumes Filament components. Demo panels have no `->tenant()` yet, but the ERP consumer will — tenancy must be designed now, not retrofitted.

**Package skeleton gap to fix outside this document:** `packages/OoMyCache/composer.json:19-22` currently requires only `illuminate/support`. Core needs `illuminate/database` (Builder/Model types), `illuminate/cache` (store contracts), `illuminate/console` (commands) — consistent with `03` §14. Keep `filament/*` and `livewire/*` in `suggest` (+ `require-dev` for the E2E lane) only.

## 2. How Filament executes queries

End-to-end pipeline (all paths converge on the same Eloquent builder):

```text
HTTP GET /admin/products ──► ListRecords::mount()
Livewire update (search/filter/page/action/poll)
        │
        ▼
ListRecords::bootedInteractsWithTable()            tables/Concerns/InteractsWithTable.php:52-194
        │  table = table(Table::make(...)
        │            ->query(fn () => static::getResource()::getEloquentQuery()))   ListRecords.php:127-137,242-245
        │
        ▼
Table::getQuery()  → evaluate(query closure) → applyQueryScopes() → clone()
        │                                          tables/src/Table/Concerns/HasQuery.php:71-82
        ▼
filterTableQuery:  filters → global/column search → column aggregates + eager loads
        │                                          tables/src/Concerns/HasRecords.php:51-70
        ▼
applyGrouping + applySorting + default key sort
        │                                          tables/src/Concerns/CanSortRecords.php:89-153
        ▼
paginateTableQuery: $total = toBase()->getCountForPagination()          ← cached via base builder (architecture §2.1.1)
                    $query->paginate(perPage, pageName, total: $total)  ← cacheable page select
        │                                          tables/src/Concerns/CanPaginateRecords.php:41-71
        ▼
index.blade.php renders $records (lazy: null on first load)   resources/views/index.blade.php:153
```

### 2.1 Resources & pages

- `Resource::getEloquentQuery()` = `Model::query()` (`Resource.php:92-110`). Because `Model::query()` calls `newEloquentBuilder()`, **our builder is returned automatically** for opted-in models. The method is the documented per-resource override point; apps that override it still use `Model::query()` internally in practice, so interception survives.
- Tenancy nuance inside `getEloquentQuery()`: resources that opt out of tenant scoping explicitly remove the named panel scope — `$query->withoutGlobalScope($panel->getTenancyScopeName())` (`Resource.php:101-107`). Names/keys are per panel (`Panel/Concerns/HasTenancy.php:512-515` → `"{$panelId}_tenancy"`); this is another reason the panel id must be in the cache key.
- `ListRecords` declares the table state as Livewire **URL-bound public properties** — `#[Url] tableFilters`, `grouping`, `search`, `sort`, `tab`, `reordering` (`Resources/Pages/ListRecords.php:35-58`). Each is a distinct query shape; refresh/deep links replay them (e.g. `/admin/products?search=acme&sort=-created_at&filters[...]`).
- `ListRecords::makeTable()` adds resource scoping only: parent-record scoping and active-tab `modifyQueryUsing()` (`ListRecords.php:127-137`); `Resource::configureTable()` applies labels/authorize-reorder (`Resource.php:74-87`). **No page caches query results.**
- Edit/View/Create pages resolve records through route-model binding (`Model::resolveRouteBindingQuery()` → `newQuery()`; referenced from `03` §2.2) — also intercepted, also subject to tenant scoping.

### 2.2 Tables (list/records/pagination/search/sort/filter/global search)

1. **Rebuild per request.** `bootedInteractsWithTable()` runs on every Livewire request via the `booted` lifecycle hook (`InteractsWithTable.php:52-194`; Livewire `Features/SupportLifecycleHooks/SupportLifecycleHooks.php:41-60`) and rebuilds the `Table` object from the page's `table()` closure. Table state is public Livewire state (`tableSearch`, `tableFilters`, `tableSort`, page…), persisted as URL/session (session keys are tenant-scoped: `InteractsWithTable.php:278-293`, `CanSearchRecords.php:375-385`).
2. **Fresh builder every render.** `Table::getQuery()` evaluates the query closure then `applyQueryScopes()` and `clone()` (`Table/Concerns/HasQuery.php:59-82,71-82`). For `ListRecords` the closure is `Resource::getEloquentQuery()` → brand-new `Model::query()`; for relation managers it is `$relationship->getQuery()` (§2.3).
3. **Filter/search shape** (`Concerns/HasRecords.php:31-70`, `HasFilters.php:190-215`, `CanSearchRecords.php:71-209`):
   - filters first (`Filter::applyToBaseQuery()` then grouped `where(...)`);
   - column searches (nested `tableColumnSearches`, individually searchable columns; LIKE patterns per column);
   - global table search: split into words (`str_getcsv`, `CanSearchRecords.php:136-142`), OR group across globally searchable visible columns, LIKE `%term%`; custom `searchUsing()` callbacks replace this;
   - visible-column relationship aggregates (`withCount`-style correlated subqueries) and eager loads are added to the query *by the table layer* (`HasRecords.php:57-67`) — so the same page's SQL includes relation aggregates we did not write. Our dependency recorder must read builder state, not resource code. **⚠ unverified — validate in POC** that all aggregate names Filament adds are recoverable from the builder (`withAggregate` overrides in `QueryCacheBuilder`).
4. **Sort shape** (`CanSortRecords.php:89-153`): user sort column → `orderBy`/`orderByRaw` via column; default sort closure may return a Builder; default key sort appended for stability; SQL Server order de-dup. Distinct keys per `tableSort` value — cache-friendly.
5. **Pagination/records** (`Concerns/HasRecords.php:112-209`, `CanPaginateRecords.php:41-71`, `Table/Concerns/HasRecords.php:106-109`): `getTableRecords()` memoises in `$cachedTableRecords` **per request only**; paginated path as in the diagram; `pageName` is `$this->getIdentifiedTableQueryStringPropertyNameFor('page')` (`CanPaginateRecords.php:124-127`), i.e. can be prefixed by `queryStringIdentifier()` for multiple tables in one component. Our `paginate()` override **must accept and forward Laravel 13's 5th `$total` argument** (`Eloquent/Builder.php:1177-1180`) and must key by `['intent' => 'paginate', page, perPage]` because page size/limit are not yet in the base SQL at key time.
6. **The paginator COUNT (resolved by design).** Default `PaginationMode::Default` **always** issues `$query->toBase()->getCountForPagination()`; `Simple`/`Cursor` modes do so for the `'all'` page size (`CanPaginateRecords.php:49-61`). `toBase()` = `applyScopes()->getQuery()` (`Eloquent/Builder.php:2095-2097`); because our trait also subclasses the **base** query builder (`newBaseQueryBuilder()` → `QueryCacheQueryBuilder`), `getCountForPagination()` is intercepted and cached (architecture §2.1.1), so a warm render costs no table data/count queries. Group-by/having/union count shapes are validated in POC and fall back to uncached when unsafe. `getAllTableRecordsCount()` uses Eloquent `count()` (`HasRecords.php:341-349`) and is cacheable too — note Filament test macro `assertCountTableRecords` calls it (`tables/src/Testing/TestsRecords.php:64-77`).
7. **Lazy tables.** Tables are **not** lazy by default (`Table/Concerns/CanBeLazyLoaded.php:9-42`, `deferLoading()` deprecated alias). When a resource enables it, first page load renders a skeleton and `loadTable()` triggers a second Livewire request that runs the query (`Concerns/CanBeLazyLoaded.php:9-42`, `index.blade.php:153`). Query count unchanged overall; first paint faster.
8. **Polling.** `Table::poll('10s')` (`Table/Concerns/CanPollRecords.php:9-21`) renders `wire:poll.{{interval}}` on the table content (`resources/views/index.blade.php:932-942`) — a full Livewire update every interval; with cache hits the table becomes a cheap read.
9. **Search request shape.** Search field uses `wire:model.live.debounce.500ms` (default 500ms; `tables/resources/views/components/search-field.blade.php:1-16`), Enter forces `$wire.$refresh()` (`:49`). Each keystroke = one Livewire update = one complete table query. **Bypass by default (HR-5 / ADR-4).**
10. **Row actions.** Resolving a record for a row action runs another query: `resolveTableRecord()` → `$this->getTable()->getQuery(isResolvingRecord: true)` + `find($key)` (`HasRecords.php:214-264`). Cached `find()` avoids this extra round trip.
11. **Export.** `getTableQueryForExport()` applies filters+search+sort but no pagination (`HasRecords.php:88-98`) — potentially huge; export should be excluded from caching by classifier (size guard) or explicitly bypassed.
12. **No islands.** Filament 5.11.2 does not use Livewire `@island` (grep: zero occurrences in `filament/{filament,tables,widgets}/resources/views`) — there is no partial-render/partial-query optimisation inside a page; every request re-renders the component tree.
13. `Table::LOADING_TARGETS` (`tables/src/Table.php:58-79`) is only a `wire:target` loading-indicator list — not a partial-reload mechanism.

### 2.3 Relation managers (and nested tables)

- A relation manager is its own Livewire component (`Resources/RelationManagers/RelationManager.php:55`), **lazy-loaded by default** (`Filament\Support\Concerns\CanBeLazyLoaded::$isLazy = true` → `isLazyLoaded()`, `vendor/filament/support/src/Concerns/CanBeLazyLoaded.php:8-33`; `RelationManager::getDefaultProperties()` adds `lazy`, `:352-362`). Each tab opens a separate Livewire request that runs its table query on the related model.
- Table wiring: `makeTable()` → `makeBaseRelationshipTable()->relationship(fn () => getRelationship())->queryStringIdentifier(Str::lcfirst(class_basename(...)))` (`Resources/Concerns/InteractsWithRelationshipTable.php:122-129`); `getRelationship()` = `$ownerRecord->{relationshipName}()` (`:67-70`).
- Query source: `Table::getQuery()` → `getRelationshipQuery()` → `$relationship->getQuery()` and pivot handling (`BelongsToMany` selects pivot columns/casts; `HasOneOrManyThrough` fixes select; `Table/Concerns/HasQuery.php:77-123`). Pivot-table ordering columns live in the pivot query, not the related model's builder — **a remaining uncached/eventless path** (§5).
- Owner record is locked component state: `#[Locked] public Model $ownerRecord` (`RelationManager.php:71-72`); tenant scoping arrives through the owner record's relation constraints.
- Tab badge queries (`RelationManager::getBadge()`, `:195-199`) are user code executed per tab render (frequently a `count()`); advise caching/avoiding in resource docs — a reason our `count()` caching matters.
- Nested/related resources and `ManageRelatedRecords` use the same table machinery; nested-resource global search is disabled unless custom results URL (`HasGlobalSearch.php:87-100`).

### 2.4 Widgets

- `Widget extends Livewire\Component` (`widgets/src/Widget.php:11-13`); **widgets are lazy by default**: `getDefaultProperties()` returns `['lazy' => true]` when `isLazyLoaded()` (`:106-119`), merged into the Livewire tag by `resources/views/components/widgets.blade.php:37`. Each dashboard widget = one extra Livewire request = its queries run outside the page request. Cache hits convert that fan-out into cheap reads.
- `StatsOverviewWidget`: `getStats()` is user code; `getCachedStats()` memoises per request (`:23,97-112`). Typical `count()/sum()` aggregates → cacheable via our aggregates overrides.
- `ChartWidget`: `getData()` user code, memoised `$cachedData` (`:25,68-71`); `mount()` stores `dataChecksum` (`:52-56`); `rendering()` calls `updateChartData()` on **every render** (`:133-136`), which re-generates data/checksum and dispatches to JS when changed (`:120-130`). `HasChartData`/filters spawn extra queries per render.
- **Default polling is 5s for stats/chart widgets** — `Widgets\Concerns\CanPoll::$pollingInterval = '5s'` (`widgets/src/Concerns/CanPoll.php:7-12`), consumed by `chart-widget.blade.php:88-89` (`wire:poll.5s="updateChartData"`) and `stats-overview-widget.blade.php:5,17`. **⚠ surprising default — validate runtime in POC**; if real, a dashboard with 4 widgets re-queries every 5s (≈48 extra query sets/minute/panel) — cache TTL/jitter and bypass policy must account for it.
- `TableWidget`: full table machinery, forced `PaginationMode::Simple` (`widgets/src/TableWidget.php:15-52`) and a default 10s poll if configured (`Table/Concerns/CanPollRecords.php:11`).
- Page widgets are registered via `Page::getHeaderWidgets()/getFooterWidgets()` (`filament/src/Pages/Page.php:477-480,505-508`); panel widgets via `Panel::widgets()` (`Panel/Concerns/HasComponents.php:228`) and `getWidgets()` (`:490`).

### 2.5 Forms/schema Select options & searchable relationships

- `Select::relationship()` (`forms/src/Components/Select.php:798`), `searchable()` (`Concerns/CanBeSearchable.php:26-31`, default search debounce 1000ms at `:16`).
- Relationship search path: `getSearchResultsFromRelationship()` builds the relation **without constraints** (`Relation::noConstraints`), prepares it via `RelationshipJoiner::prepareQueryForNoConstraints()`, applies the search LIKE, caps with `optionsLimit` (default 50) and `pluck()`s the label/id (`Select.php:1002-1066`). It is invoked from the searchable select component (`:793-808`) through `#[Renderless] getSearchResultsForJs()` (`:740-745`) — a Livewire request that deliberately skips rendering; only these option queries execute.
- Non-searchable/preloaded modes load all options via `getOptionsFromRelationship()` (`:1071-1153`), and label resolution for existing values (`getOptionLabel(s)`) queries by bound IDs — **bounded, high-reuse, tenant-scoped reads that are ideal cache candidates** (customers/products/suppliers in ERP forms).
- These relation queries are built from the related model's `newQuery()` (Relation constructor → `$related->newQuery()`), so an opted-in related model gets our builder. **⚠ unverified — validate in POC** that builder class propagates through `Relation::noConstraints()`/`RelationshipJoiner` prep (type checks in Filament expect `Illuminate\Database\Eloquent\Builder`, which our subclass satisfies).
- Global-search topbar is different: it runs **one query per resource per keystroke** (`GlobalSearch/Providers/DefaultGlobalSearchProvider.php:10-36`, `Livewire/GlobalSearch.php:13-41`, rendered in `sidebar.blade.php:132`), results limited to 50 (`HasGlobalSearch.php:26`) and policy-checked **after** the query when building URLs (`:87-100`). Bypass by default; never share across tenants/panels.

### 2.6 Actions & bulk actions (query + mutation paths)

Action execution: mounted action → optional DB transaction → `callBefore()` → action closure → `callAfter()` → commit → `ActionCalled` event (`InteractsWithActions.php:417-482`; `HasLifecycleHooks.php:66-80`; event class `Actions/Events/ActionCalled.php:8-20`). Selected-record resolution for bulk actions rebuilds a filtered/sorted builder and constrains it with `whereKey(...)`/`whereKeyNot(...)` (`tables/src/Concerns/HasBulkActions.php:326-382`) — another read that flows through our builder.

| Filament action | Eloquent mutation (evidence) | Model events? | Implication for cache |
|---|---|---|---|
| `EditAction` | `$record->update($data)` (`EditAction.php:107`), pivot `$pivot->update()` (`:97`) | yes (instance) | core `saved` listener invalidates |
| `CreateAction` | `$record->save()` / relation `save()` (`CreateAction.php:85-105`) | yes | `saved`, plus related model covers relation |
| `DeleteAction` / `ForceDeleteAction` / `RestoreAction` | `$record->delete()` (`DeleteAction.php:52`), `->forceDelete()` (`ForceDeleteAction.php:42`), `->restore()` (`RestoreAction.php:48`) | yes (`deleting/deleted`, `restoring/restored`, `forceDeleting/forceDeleted`) | core listeners invalidate |
| `ReplicateAction` | `$record->replicate()` + `$replica->save()` (`ReplicateAction.php:65-71`) | `saved` on replica | invalidates base model namespace |
| `AssociateAction` / `DissociateAction` | `->associate()`/`->dissociate()` then `$record->save()` (`AssociateAction.php:104-106`, `DissociateAction.php:47-49`) | yes | saved |
| `AttachAction` / `DetachAction` | `$relationship->attach()` (`AttachAction.php:116`), `->detach()` / custom-pivot `->delete()` (`DetachAction.php:48-51`) | **no model events for related model** (pivot insert/delete via query builder, `InteractsWithPivotTable.php:333-355,519-550`) | bridge flush required |
| `DeleteBulkAction` (default) | per-record `delete()` loop (`DeleteBulkAction.php:99-114`) | yes, one per record | core listener fires N times (coalesce boosts) |
| `DeleteBulkAction` + `fetchSelectedRecords(false)` | `getSelectedRecordsQuery()->delete()` (`:83-95`) → Eloquent `Builder::delete()` → `toBase()->delete()` (`Eloquent/Builder.php:1563-1571`; `forceDelete()` at `:1579-1581`) | **no** | builder write override + bridge |
| `ForceDeleteBulkAction` / `RestoreBulkAction` (mass branch) | `->forceDelete()` / `->restore()` (`ForceDeleteBulkAction.php:83-95`, `RestoreBulkAction.php:83-95`); restore macro is a mass UPDATE (`SoftDeletingScope.php:73-79`) | **no** | builder write override (`restore` macro dispatches our `update()`) + bridge |
| `DissociateBulkAction` (mass branch) | `getSelectedRecordsQuery()->update([...])` (`DissociateBulkAction.php:49-66`) | **no** | builder write override + bridge |
| `table.reorderTable` | model path: `getQuery()->whereIn()->update([...])`; pivot path: `newPivotQuery()->update()` (`CanReorderRecords.php:24-68`) | **no** (builder mass update / pivot query) | builder write override for model path; pivot path needs bridge/explicit flush |
| `ImportAction` | `Importer` saves per record and attaches pivots (`Imports/Importer.php:286,372,402`) | record events yes; pivots no | bridge flush after import |

`CanFetchSelectedRecords::$shouldFetchSelectedRecords = true` default (`CanFetchSelectedRecords.php:9-23`) is good news: the app must explicitly opt into the event-bypassing mass path. Bridge can also listen to `ActionCalled` as a belt-and-braces flush for *any* action touching a model, regardless of whether core detected a write.

## 3. How Livewire 4 re-executes queries

### 3.1 Request lifecycle (verified)

```text
Initial GET:  new component → mount() hooks → render() → dehydrate() → snapshot (HandleComponents.php:36-85)
Livewire POST update:
   fromSnapshot(): checksum verify + hydrate public state   (HandleComponents.php:247-264)
   hydrate hooks → updateProperties() → callMethods()        (:220-225)
   render()  ← Filament tables/widgets re-run their queries  (:228-231, 311-356)
   dehydrate snapshot → HTML effects                         (:233-241)
```

- Lifecycle hooks: `boot` → `initialize` → `mount`/`hydrate` → property `updating*/updated*` → `rendering/rendered`; `booted` runs **both** on mount and on every hydrate (`SupportLifecycleHooks.php:25-60`). That is why `bootedInteractsWithTable()` rebuilds the table each request.
- State that is `protected` (Filament's request caches, our per-request memo) is not serialised; only public properties are dehydrate/hydrated and validated against the checksum (`HandleComponents.php:266-309`). `#[Locked]` properties are protected from client tampering (`features/SupportLockedProperties/BaseLocked.php`; e.g. `RelationManager::$ownerRecord`).
- Request types that execute queries: initial page mount, any state update (`wire:model` debounce), exposed method call (`#[Exposed]`), action call/mount, `wire:poll`, lazy/deferred component load (`SupportLazyLoading.php:48-90`), renderless calls (Select search, `#[Renderless]`, `getSearchResultsForJs`).
- **No `#[Cache]` attribute exists in Livewire 4.4.8.** Attribute directory: `Async, Authorize, Computed, Defer, Isolate, Js, Json, Layout, Lazy, Locked, Modelable, On, Reactive, Renderless, Rule, Session, Title, Transition, Url, Validate` (`vendor/livewire/livewire/src/Attributes/`). The checklist item `#[Cache]` and `05` F17 must be reworded to `#[Computed(cache: true)]`.

### 3.2 `#[Computed]` semantics (and why we must not misuse it)

`#[Computed]` extends `SupportComputed\BaseComputed` (`Attributes/Computed.php:1-8`; `Features/SupportComputed/BaseComputed.php:10-21`). Semantics:

- default: **request-scoped memoisation only** (`$requestCachedValue`, `:13,49-51`), cleared on unset (`:54-71`). This is exactly what we want (safe; mirrors Filament's own request caches).
- `persist: true` → `Cache::remember('lw_computed.{componentId}.{name}', $seconds)`; `cache: true` → `Cache::remember('lw_computed.{componentName}.{name}', $seconds)` (`:73-99,145-157`). ⚠ `cache:true` key contains **no tenant/panel/user segment** and defaults TTL 1h.
- Cached Eloquent models can unserialize as `__PHP_Incomplete_Class`; Livewire detects this, logs a warning in debug and recomputes (`:101-129`), which would silently defeat the cache.
- **Finding:** Livewire's built-in computed cache must never be used on tenant-scoped Filament page/widget components — it can serve tenant A's value to tenant B. OoMyCache's own cache (tenant/panel keyed) is the correct place for persistent caching. Bridge doctor should warn about `#[Computed(cache: true)]` / `persist: true` on Filament components (static check P2, runtime warning in debug).

### 3.3 Polling, lazy/defer, partial reloads

- `wire:poll` renders a full Livewire update; see table poll (§2.2.8) and widget poll default 5s (§2.4). Polled queries are cacheable; TTL should be ≥ poll interval to be useful but invalidation remains event-driven, not TTL-driven.
- Lazy/defer: component (`#[Lazy]`/`#[Defer]`, `SupportLazyLoading.php:48-90`) and Filament's own lazy trait (`Support\Concerns\CanBeLazyLoaded`, tables/RMs/widgets) postpone the first query into a follow-up request. Consequence: **warm caches make lazy loading much faster**, but the network round-trip remains; do not advertise lazy-loading removal.
- Filament table search debounce 500ms, Select search debounce 1000ms (`search-field.blade.php:15`, `CanBeSearchable.php:16`). During typing, each request is a distinct SQL shape → bypass (HR-5).
- Livewire islands/`wire:sort` etc. are not used by Filament 5.11.2 (`@island` grep: none), so there is no partial-query optimisation to account for.
- Tests: `Livewire::withoutLazyLoading()` exists for deterministic tests (`LivewireManager.php:223-228`); `Livewire::test()` (`:238-247`); state reset via `Livewire::flushState()` (`:293-296`).

## 4. Tenancy, auth & isolation (threat model; key composition; multi-panel/multi-guard)

### 4.1 How Filament resolves tenant/panel/guard (verified)

- **Panel**: `Panel::getId()` (`Panel/Concerns/HasId.php:24-33`); current panel is request state on the container-scoped `FilamentManager` (`FilamentServiceProvider.php:72-76`; `FilamentManager.php:49-59`), set by `SetUpPanel` middleware per request (`Http/Middleware/SetUpPanel.php:11-20`).
- **Tenant**: resolved from the route parameter by `IdentifyTenant` after authentication (`Http/Middleware/IdentifyTenant.php:11-47`), set via `Filament::setTenant()` (`FilamentManager.php:937-944`), read via `Filament::getTenant()` (`:453-456`). `IdentifyTenant` is in Filament's default **persistent middleware** list re-run on Livewire update requests (`FilamentServiceProvider.php:147-157`), so tenant re-identification works on AJAX requests as long as the original route carried `{tenant}`.
- **Query scoping**: tenancy is implemented as a *conditional global scope* registered per panel: `registerTenancyModelGlobalScope()` adds `"{$panelId}_tenancy"` to every non-opted-out resource model; the scope applies only when `Filament::getCurrentPanel() === $panel` **and** a tenant is set (`BelongsToTenant.php:128-157`). Explicit opt-out resources remove the scope in `getEloquentQuery()` (`Resource.php:101-107`). **Therefore: console/queue/early-middleware queries are not tenant-scoped**, and the same model can legitimately produce different SQL depending on request context.
- **Auth/guard**: `Panel::auth()` = `auth()->guard($panel->getAuthGuard())` (`HasAuth.php:300-303,664-667`); `Authenticate` forces the guard and verifies `FilamentUser::canAccessPanel()` (`Http/Middleware/Authenticate.php:15-41`).
- **Impersonation**: grep for `impersonat` across `vendor/filament/**/*.php` → **zero hits**. Filament 5.11.2 has no `Filament::getImpersonatedUser()`/impersonation API. Policy must be: (a) if the app implements impersonation by switching the authenticated user, tenant-scoped SQL + optional `include_user` segment cover it; (b) if it switches users without flushing our cache and user segment is off, correctness relies purely on SQL scoping — document and add a host-app flush call on impersonation start/stop; (c) bridge doctor warns if an impersonation package is detected. **⚠ unverified — depends on the ERP's impersonation implementation.**
- **User-scoped identifier helper**: Filament already has the exact hash we need for optional user segregation: `Filament::getUserScopedAuthIdentifier()` = `sha256(encode_key_segments(guard, userClass, authIdentifier))` (`FilamentManager.php:630-643`). Reuse this algorithm in `TenantResolver` when `include_user` is enabled (never copy the user ID raw).

### 4.2 Key composition algorithm (bridge-aware `TenantResolver`)

```text
segments = [
  config('oomycache.prefix'),                       // "oomycache"
  config('oomycache.version'),                      // format/deploy version
  tenant:  class-short + key | 'none' | BYPASS     // from Filament::getTenant()
  panel:   Panel::getId() | 'none'                  // Filament::getCurrentPanel()
  user:    Filament::getUserScopedAuthIdentifier() | 'none'   // only if include_user
  query-hash: xxh128(model, connection, sql, canonical bindings, columns, eager/aggregate deps, salt, intent)
]
```

- Resolve segments **at query-execution time** (inside the builder), never at boot/static state: tenant/panel switch mid-process (Octane, tests, panel switch without logout) must produce different keys immediately.
- `panel` is included whenever a current panel exists (ERP has 8 panels; `getEloquentQuery()` overrides, global scopes and policy visibility differ per panel).
- **Bypass (do not cache) when:** current panel `hasTenancy()` (`HasTenancy.php:207-210`) and `Filament::getTenant()` is null and no explicit `TenantResolver` is bound (fail-closed HR-3); `Filament::getCurrentPanel()` is null while tenancy models are involved and no resolver is bound; a `lockForUpdate`/shared-lock query is detected; inside an open transaction (HR-6).
- **Resource deliberately unscoped** (`scopeToTenant(false)`): the model is shared reference data; it may only be cached cross-tenant when the app declares it via per-model config (`share_across_tenants: true`) — default is fail-closed. Never infer "shared" from the absence of a tenant field.
- **Never share:** global-search results, per-user filtered dashboard stats when `include_user` is off but SQL does not encode the filter (documented limitation, `03` §10.4), pivot-derived counts across tenant boundaries, and anything produced while no tenant was resolved.

### 4.3 Multi-panel/multi-guard matrix

| Scenario | Tenant in key | Panel in key | Guard/user in key | Expected behaviour |
|---|---|---|---|---|
| Same model, same panel, user A/B, SQL identical | one | one | off (default) / on (opt-in) | shared unless `include_user` |
| Tenant switch (route param change) | different | same | — | new key, miss, no stale rows |
| Panel switch (admin ↔ ERP panel) | per panel config | different | — | separate namespaces |
| Console/queue write or read | fail-closed read bypass | n/a | n/a | never caches unscoped rows |
| Livewire request without `{tenant}` in original route | no tenant resolved → **bypass** | panel | — | no cross-tenant reuse |
| Impersonation (app-level user switch) | per above | same | recommended on | flush on switch or include user *(⚠ unverified app-specific)* |

## 5. Invalidation matrix: Filament operation → Eloquent event → cache action

Legend: ✅ safe (events/builder override), ⚠️ gap needing bridge/explicit flush, ❌ uncacheable path.

| # | Filament operation | Verified code path | Eloquent events | Core action | Bridge action | Notes |
|---|---|---|---|---|---|---|
| 1 | Edit form / `EditAction` | `$record->update()` (`EditAction.php:107`) | `updating/updated/saved` | bump model namespace (after commit) | — | ✅ |
| 2 | Create page / `CreateAction` | `$record->save()`, relation `save()` (`CreateAction.php:85-105`) | `creating/created/saved` | bump model | — | ✅ |
| 3 | Single delete/force-delete/restore | `$record->delete()/forceDelete()/restore()` (`DeleteAction.php:52`, `ForceDeleteAction.php:42`, `RestoreAction.php:48`) | deleted/restored/forceDeleted | bump | — | ✅ |
| 4 | Replicate | `replicate()+save()` (`ReplicateAction.php:65-71`) | `saved` (replica) | bump | — | ✅ |
| 5 | Associate/Dissociate (BelongsTo) | `->associate/dissociate(); $record->save()` | `saved` | bump base model | flush declared related-model dependency | ⚠️ dependency side |
| 6 | Attach/Detach (BelongsToMany) | `attach()`/`detach()` (`InteractsWithPivotTable.php:333-355,519-550`) | **none for related model** | builder cannot see it | flush both sides via `ActionCalled` | ⚠️ bridge required |
| 7 | Bulk delete/force-delete/restore (default) | per-record loop `DeleteBulkAction.php:99-114` etc. | yes, N times | bump (coalesced) | optional belt-and-braces flush | ✅ |
| 8 | Bulk delete/force-delete/restore with `fetchSelectedRecords(false)` | mass `->delete()/forceDelete()/restore()` (`DeleteBulkAction.php:83-95`, `RestoreBulkAction.php:83-95`) | **none** (`Eloquent/Builder.php:1563-1571,1579-1581`; `SoftDeletingScope.php:73-79`) | builder write override bumps | flush after `ActionCalled` | ⚠️ both |
| 9 | DissociateBulk / DetachBulk | `update([...])` or pivot detach (`DissociateBulkAction.php:49-66`, `DetachBulkAction.php:60-81`) | **none** (builder update/pivot query) | builder update override bumps; pivot path invisible | flush after `ActionCalled` | ⚠️ pivot path bridge-only |
| 10 | Table reorder | model path `getQuery()->whereIn()->update()`; pivot path `newPivotQuery()->update()` (`CanReorderRecords.php:24-68`) | **none** | model path caught by builder update override; pivot path ❌ | bridge flush on `reorderTable` (no action event — needs Livewire listener/hook) | ⚠️ pivot ordering |
| 11 | Import (`ImportAction`) | per-record saves + pivot attach (`Imports/Importer.php:286,372,402`) | records yes, pivots no | bump per record (coalesce P1) | flush after import completed / action finished | ⚠️ batch perf |
| 12 | Relation manager create/edit/delete | runs through related model / table actions | same as #1-8 | bump related model | — | ✅ |
| 13 | `filterTableQuery` relationship aggregates | `withCount`-style subqueries added by table columns (`HasRecords.php:57-67`) | — (read) | includes related-version deps (recorded) | — | ⚠️ POC verify recorder sees them |
| 14 | Custom action with `Model::query()->update()/delete()` | arbitrary app code | none | builder write override if model opted-in | recommend explicit `flushQueryCache()` in docs | ✅ if builder used |
| 15 | Raw `DB::table()->update()/delete()` | arbitrary | none | ❌ not interceptable | explicit `OoMyCache::invalidate()` / `clear()` documented | ❌ documented limitation |
| 16 | `toBase()` count during pagination | `CanPaginateRecords.php:61` | — (read) | cached via base `QueryCacheQueryBuilder::getCountForPagination()` (architecture §2.1.1) | — | ✅ simple count; group/having fallback POC (§10.1) |
| 17 | Search-term queries (table/column/global search) | §2.2.3, §2.5 | — (read) | bypass (`search-term`) | — | ADR-4, HR-5 |
| 18 | Select relationship search | `Select.php:1002-1066` | — (read) | bypass or tiny TTL (opt-in) | — | high cardinality |

**Bridge flush implementation sketch:** listen to `Filament\Actions\Events\ActionCalled` (`Actions/Events/ActionCalled.php:8-20`); resolve the action's model via `$action->getModel()` / record via `$action->getRecord()` (`Actions/Concerns/InteractsWithRecord.php:102-139,302-335`) or, for bulk/table actions, the table model via `$action->getLivewire()->getTable()->getModel()` (`BelongsToLivewire.php:12-38`, `HasRecords.php:137-154`); then `OoMyCache::invalidate($modelClass)` for the configured "mass-write-capable" action list (`delete`, `force-delete`, `restore`, `detach`, `dissociate`, `attach`, `associate`, `replicate`, `import`, `reorder`). Listener runs synchronously inside `callMethods()` before render (§0.4). **⚠ unverified:** exact action→model resolution for every bulk/header action must be POC-tested (some header actions have no table); fall back to `OoMyCache::clear(scope: 'panel')`.

## 6. Hook architecture for the package

### 6.1 Candidate hooks and tradeoffs

| Hook | Filament coverage | Verdict |
|---|---|---|
| **Custom Eloquent builder via trait + `newEloquentBuilder()`** (ADR-1) | 100% of reads; all write methods on opted-in models | **Chosen.** Transparent, type-compatible, no framework patches. Filament only requires `Illuminate\Database\Eloquent\Builder` — subclassing is invisible. |
| Global scope (`addGlobalScope`) | only adds constraints; cannot intercept execution/results | Rejected (cannot cache or return alternative payloads). |
| Builder macros (registered globally) | only if app calls them; Filament doesn't | Rejected as primary (useful as DX sugar only). |
| DB query listener / `DB::listen` | observes only; cannot substitute results; misses model hydration semantics | Rejected for read cache; usable for observability/stats. |
| Filament/Livewire component hooks | would couple to internal Flow state (handled per-request), fragile | Rejected for core; only bridge UI invalidation hooks. |
| `toBase()`/`newBaseQueryBuilder` override | caches the paginator COUNT every default render (§2.2.6) **and** records join/subquery tables | **Chosen (P0)** — `QueryCacheQueryBuilder` via `newBaseQueryBuilder()`; group-by/having/union fallback validated in POC (architecture §2.1.1). |

### 6.2 Compatibility contract the builder must honour for Filament

`QueryCacheBuilder extends Illuminate\Database\Eloquent\Builder` and must preserve:

1. `paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)` — Laravel 13 signature; Filament passes `total:` (`Eloquent/Builder.php:1177-1193`). Rebuild paginator with the exact `path`/`pageName` Filament expects (`CanPaginateRecords.php:63-70`).
2. `simplePaginate()` (TableWidget, `PaginationMode::Simple`) and `cursorPaginate()` — cursor page keys include the effective cursor predicate.
3. `get()`/`first()`/`find()` — `find()` is used by `resolveTableRecord()` (`HasRecords.php:224-264`); must accept compound keys/`findMany`.
4. `count()`/aggregates — used by table totals helpers, tab badges, stats widgets (`HasRecords.php:341-349`, `RelationManager.php:195-199`).
5. `withCount`/`withSum`/…/`has`/`whereHas`/`whereBelongsTo`/`with` recording — Filament adds column aggregates/eager loads itself (`HasRecords.php:57-67`); return `$this`, keep parent behaviour, record dependency names before delegating (`03` §5.3).
6. Write methods: `update`, `delete`, `forceDelete`, `increment`, `decrement`, `upsert`, and the `restore()` soft-delete macro (macro closes over `$builder` and calls `$builder->update()` → dispatches to our override; `SoftDeletingScope.php:73-79`) — bump namespace after the write (after commit if in transaction).
7. Never change builder identity semantics: `clone()` must keep class; `toBase()` untouched (documented hole); `lockForUpdate`/`sharedLock` queries bypass (classifier detects `$this->query->lock`).
8. No eager mutation of Filament's request-scoped caches; no reliance on `app()` state in hot path beyond scoped services.

### 6.3 Bridge package layer (optional, auto-detected)

```
src/Filament/                     ← only place referencing Filament/Livewire
  FilamentBridgeServiceProvider.php   registers only if class_exists(\Filament\Panel::class)
  OoMyCachePlugin implements Filament\Contracts\Plugin     (register()/boot(), get id 'oomycache')
  Pages/QueryCachePage.php            (Filament\Pages\Page; status + counters + per-model token table)
  Widgets/CacheStatsOverviewWidget.php (StatsOverviewWidget; reads stats snapshot from cache)
  Actions/FlushModelCacheAction.php   (header/table action; permission-checked)
  Listeners/FlushOnActionCalled.php   (ActionCalled → invalidate; §5)
  Support/VersionGate.php             (Filament 5.11+/Livewire 4.4+; refuses boot otherwise)
  Support/StatsCollector.php          (queue-safe counters shared with core events)
```

- Registration is **explicit per panel** (`$panel->plugin(OoMyCachePlugin::make())`) because the app has 8 panels and not every panel should expose cache operations. Plugin contract: `vendor/filament/filament/src/Contracts/Plugin.php:7-14`; registration/boot semantics `Panel/Concerns/HasPlugins.php:15-21`, `Panel.php:87-115`. `register()` runs at panel registration; `boot()` runs on each request via `bootCurrentPanel()` (guarded by the request-scoped `$isCurrentPanelBooted`, `FilamentManager.php:68-76`). **Plugin must be stateless/idempotent** (Octane: `Panel`/plugin instances live in the singleton `PanelRegistry`, `FilamentServiceProvider.php:78-81`; only the scoped `FilamentManager` resets per request).
- The page/widget must not run unbounded `count()` loops: read the stats snapshot (one cache read) and show per-model token timestamps; the model list comes from config/registry (bounded).
- Filament's `filament:cache-components` writes `bootstrap/cache/filament/panels/{panelId}.php` (`HasComponents.php:723-797`; commands `Commands/CacheComponentsCommand.php:9-27`, `ClearCachedComponentsCommand.php:9-27`). Plugin pages/widgets registered in `register()` are included when the cache is generated; **after installing/upgrading the bridge, document `php artisan filament:clear-cached-components && php artisan filament:cache-components`** so the panel cache is regenerated. `oomycache:doctor` should detect a cache file older than the installed bridge version (⚠ POC).

### 6.4 Opt-in/out model and safe ERP defaults

- Opt-in: `use QueryCacheable;` trait (`newEloquentBuilder()`), per-model `queryCacheOptions()` (`02` §2.2), per-query `->cacheFor()` API (`02` §3), config blocklist/allowlist.
- Default cache decisions for Filament workloads: cache `paginate/simplePaginate/cursorPaginate/get/first/find/count/aggregates`; cache aggregate/filter/sort shapes; **bypass** search-term queries (LIKE with leading wildcard/`%`), unlocked-by-policy `toBase`, exports, transaction reads, pivots/raw queries, `lockForUpdate`, unbound morphs, unfingerprintable eager-load closures.
- ERP-safe defaults (recommend in `02`): TTL 60–120s page queries, 15–30s dashboard aggregates, jitter 10–20%; `safety.skip_reads_in_transactions=true`; `invalidate_after_commit=true`; no SWR for inventory/finance models; per-model kill switch for tables with counted relations until the aggregate recorder is verified.
- `bridge.flush_on_actions`: list of action names to flush on (`delete`, `force-delete`, `restore`, `detach`, `dissociate`, `attach`, `associate`, `replicate`, `import`, `reorder`) + `bridge.flush_on_relation_manager_actions` (default on for Attach/Detach/Associate/Dissociate — required by HR-4, see `01` §3.2).

### 6.5 Same-request freshness rule

Because `callMethods()` (mutation) and `render()` (read) happen in one Livewire request (`HandleComponents.php:220-241`), invalidation must also clear the **per-request memo**. Rule: every version-token read inside the request must consult the store-backed token (or `app()->scoped()` memo) and any invalidation bumps a request-scoped "dirty" marker that empties the memo. Otherwise a save-then-render within one request can serve the pre-save payload from memo. Test: F7 same-request freshness (§9).

## 7. Filament integration UX

### 7.1 Minimum viable (P0, ERP v1)

| Surface | Spec |
|---|---|
| Panel plugin | `OoMyCachePlugin` id `oomycache`, explicitly added to the ERP panel only; no global middleware, no route collisions; renders under any theme (Filament components only). |
| Query Cache page | `Filament\Pages\Page` in a “System” nav group; nav label/icon via page statics; content = status card (enabled, store driver, version token, hit/miss counters, bypass reasons top-5), per-model table with current version token + last invalidation time + row counts, buttons: `Invalidate` (O(1) bump, default), `Purge` (physical, confirmation), `Clear all` (global token, typed confirmation). Uses Filament table/section/badge/button components only. |
| Authorization | `Page::canAccess()` + per-action checks call a configured gate: `config('oomycache.filament.authorize')` (Closure/class-string) falling back to Laravel gate `oomycache.manage`; **never panel-access-only**. Unauthorized: 403 (page) / hidden button. |
| Commands | `oomycache:status`, `oomycache:clear --model= --tenant=`, `oomycache:doctor` registered by core provider (`03`/`02` §8). Filament 5.11.2 has **no command palette** (grep: none), so no palette integration is promised or deferred. |
| Stats source | Stats collector writes sampled counters to cache (`03` §11, `01` §7); page reads one snapshot key (no N+1, no per-model `count()`). |

### 7.2 Premium (P1/P2)

- Dashboard `CacheStatsOverviewWidget` (hit ratio, queries avoided, stale namespaces, store latency) — Filament components; polling explicit (recommend 60s, not the 5s default — see §2.4).
- Per-resource/table header action “Flush cache for this model” + a reusable trait for resource pages; confirmation + authorization; dispatches `oomycache:status` refresh event.
- Warmup: `oomycache:warm` queued job iterating configured tenants/resources; Filament action triggers job (never blocking request); per-tenant iteration uses `Panel::getTenant`/resolve callback (`HasTenancy.php:272-290`).
- Freshness metadata: expose payload envelope `generatedAt/expiresAt` from `03` §2.6 as a badge on the Query Cache page (observability only; never used for correctness).
- `oomycache:doctor --filament` checks: Filament/Livewire versions match bridge, panel component cache present/regenerated, tenancy configured for tenant-keyed models, `#[Computed(cache:true)]` misuse on panel components, custom `getEloquentQuery()` overrides returning non-`Model::query()` builders, searchable selects using custom `getSearchResultsUsing` closures (unfingerprintable).
- Custom theme safety: the app's `LiquidGlassPlugin` restyles Filament components via CSS; bridge markup must stay on standard Filament Blade components/classes. Test F16 renders the page inside a glass panel (assert 200 + expected text, no Vite manifest requirement; do not ship bridge CSS/JS assets in v1).

### 7.3 UI performance rules

1. Cache management page must be O(1) store reads, not O(models) DB counts.
2. Purge/clear actions are rate-limited (`filament:auth`-style limiter or action rate limit) and confirmation-gated.
3. Long lists paginate/lazy; no `->all()` registries.
4. Audit log: emit `QueryCacheCleared`/`QueryCacheInvalidated` events with actor (user id/panel) for ERP governance.

## 8. Compatibility matrix & non-goals

| Surface | Verified now | Commitment v1 | Evidence / notes |
|---|---|---|---|
| Filament | 5.11.2 | **5.11+ bridge (P0)** | panel/table APIs verified above; `filament/support` requires `livewire/livewire ^4.4.8` |
| Filament 4 | not installed | P1 lane (`05` §2) — **unverified** | Filament 4 uses Livewire 3 APIs; separate bridge major; do not claim until CI lane exists |
| Livewire | 4.4.8 | **4.4.8+ only** | `#[Computed(cache:)]` semantics verified; no `#[Cache]` |
| Livewire 3 | not installed | P1 — **unverified** | `#[\Livewire\Attributes]` API differences; bridge must version-gate |
| Laravel | 13.40.0 | 12.x + 13.x (core constraint `^12\|^13`) | Filament 5 supports illuminate ^11.28\|^12\|^13; our narrower range is safe |
| PHP | 8.5 runtime | ^8.3 | package + CI matrix (`05` §8) |
| PHPUnit/Pest | Pest 5.1.3 (app) | Pest 4.6/5 on package | `packages/OoMyCache/composer.json:30-32` |
| Cache stores | app default; array for tests | version-token strategy works on all stores (no tag dependency) | `03` ADR-2 |

**Core vs bridge split:** core requires only `illuminate/{support,database,cache,console,contracts}`; bridge is `osamaelnagar/oomycache` sub-namespace with `suggest: filament/filament, livewire/livewire` and `require-dev` entries for the E2E lane. Automatic detection: `class_exists(\Filament\Panel::class) && class_exists(\Livewire\Component::class)`; if detected but versions mismatch → log critical + do not register panel plugin (core still works). No optional middleware, no route publishing conflicts, no config publishing conflict.

**Non-goals (explicit):** Filament 3 / Livewire 2–3 support in v1; automatic resource/model registration; caching by HTTP response; searching-term/global-search caching by default; cross-panel or cross-tenant cache sharing; patching Filament/Livewire internals or `toBase()`; a Filament “command palette” integration (absent in 5.11.2); bridging `filament:cache-components` internals beyond docs/doctor checks; caching Filament form state; replacing Filament's request-scoped caches.

## 9. Test plan (Pest + Testbench + Filament/Livewire E2E lane)

**Setup.** Package Pest/Testbench as skeleton (`testbench.yaml`, `pest.php`), plus a separate dev-dependency lane `tests/Filament/` installing `filament/filament ^5.11`, `livewire/livewire ^4.4`, `orchestra/testbench` (`05` §2/§8). Fixture: SQLite in-memory (MySQL/PostgreSQL lanes for pagination/count semantics), one panel provider with `tenant()` fixture + a second non-tenant panel, `Product`/`Customer`/`Order`/`OrderLine` models with the trait, resources `ListProducts`/`EditProduct`, a `StatsOverviewWidget`, a `ChartWidget`, a relation manager with `BelongsToMany` pivot, and a custom theme panel (liquid-glass fixture P1). Between tests: `Cache::flush()` (or `OoMyCache::clear()`), `Livewire::flushState()` (`LivewireManager.php:293-296`), fresh app via Testbench; use `Livewire::withoutLazyLoading()` unless the test targets lazy behaviour.

**Helpers.** `Livewire::test(ListProducts::class)` (`LivewireManager.php:238-247`) + Filament table macros registered by `TablesServiceProvider.php:52-57` (`assertCanSeeTableRecords`, `assertCountTableRecords`, `loadTable`, `selectTableRecords`, `callTableBulkAction`, `callAction`, `assertActionExists`; `tables/src/Testing/TestsRecords.php:20-86`, `TestsBulkActions.php:21-89`, `actions/src/Testing/TestsActions.php:84-144`). Query-count helper: `DB::listen()` collecting `QueryExecuted` per Livewire call (exclude auth/session/`cache` lookups); assert cache-visible SQL equals expected shape (count query expected to remain — see §2.2.6). `Filament::setTenant($tenant)` inside tests for tenant switch (`FilamentManager.php:937-944`), `actingAs($user, $panel->getAuthGuard())`.

| ID | Scenario | Assertions |
|---|---|---|
| F1 | Cold vs warm list page (50k rows, 25/page) | row data equal; warm executes **0 table data + 0 table COUNT queries** (base-builder count cached) |
| F2 | Page 2 / per-page / sort / filter change | distinct cache entries; correct records; no stale page overlap |
| F3 | Global table search + column search | correct results; **not cached** by default (assert cache-write events absent / bypass reason `search-term`) |
| F4 | Topbar global search | correct results; policy filtering preserved; bypassed; zero cross-tenant rows |
| F5 | Bulk delete (default) | 100 records deleted; one synchronous invalidation (coalesced); next render misses |
| F6 | Bulk delete with `->fetchSelectedRecords(false)` + `attach/detach` in relation manager | bridge `ActionCalled` flush fires **before** next render; no stale rows; pivots correct |
| F7 | Same-request freshness: edit action saves then table re-renders in same Livewire call | rendered rows reflect save; per-request memo cleared |
| F8 | Stats widget + chart widget (lazy + explicit poll) | values correct; poll request served from cache when unchanged; no double caching |
| F9 | Tenant switch A→B on same route with same filters | B never sees A rows across list/global search/select/widget; keys contain tenant+panel (assert via debug key map) |
| F10 | Multi-panel: admin vs ERP panel resource overrides | separate cache namespaces; no cross-panel assumptions |
| F11 | No tenant resolved while panel has tenancy | cache read bypassed (fail-closed), DB read still correct |
| F12 | Transaction: action transaction with save + DB read | no cached dirty reads; invalidation after commit; rollback does not invalidate |
| F13 | `#[Computed(cache:true)]` warning path / `#[Lazy]` widget | doctor/static warning; results equal; no cross-tenant computed leak |
| F14 | `filament:cache-components` lifecycle | bridge page available before/after cache generation; doctor detects stale cache (P1) |
| F15 | Bridge authorization | unauthorized user gets 403/hidden purge actions; gate wired |
| F16 | Theme compatibility (liquid-glass panel) | page + widget render 200 inside themed panel; no bridge CSS assets required |
| F17 | Query Cache page itself | O(1) reads; no DB count fan-out; purge/invalidate audited |
| F18 | Model not opted in | no builder substitution; no cache traffic; Filament unchanged |

**Gate:** the Filament lane is required for any Filament-touching PR and nightly (`05` §3); a stale-data or tenant-leak failure is a release blocker (`05` §8). Keep the core suite Filament-free (composer `test` must pass without Filament installed).

## 10. Open questions / risks to validate in POC

1. **Count shapes (highest impact).** The default table COUNT is cached by the base-builder interception (architecture §2.1.1); POC must validate group-by/having/union pagination semantics (`Query/Builder.php:3776-3795`) and measure ERP tables. If a shape is unsafe, it stays a documented one-query floor and is surfaced in `oomycache:doctor`.
2. **Filament-added relationship aggregates** (`HasRecords.php:57-67`) must be recorded for invalidation; POC verify names available after builder state inspection (`withAggregate` overrides), including hidden/grouped tables.
3. **Widget default 5s polling** (`widgets/src/Concerns/CanPoll.php:7`): confirm at runtime; if true, publish guidance and consider bridge profile disabling poll for heavy widgets (via subclass in app, not by patching).
4. **Lazy widgets/RMs separate request**: confirm tenant/panel/guard segments resolve identically in the lazy request (persistent middleware list, `FilamentServiceProvider.php:147-157`); adversarial test with `#[Lazy]` widget + tenant switch.
5. **Tenancy fail-closed edge cases**: Livewire update where original route lacked `{tenant}` (e.g. global search from a non-tenant page), queue jobs for tenant models, console commands; define the explicit `TenantResolver` escape hatch.
6. **Impersonation:** no Filament API exists (verified); POC with the ERP's actual impersonation implementation and decide whether `include_user` is mandatory for those panels.
7. **`ActionCalled`-based flush coverage:** bulk/header/relation-manager actions models (`getModel()` fallbacks), and reorder (no action event — needs a Livewire method hook or table `afterReordering` callback integration; `CanReorderRecords.php:66-68`).
8. **`filament:cache-components` interaction** with plugin pages/widgets across upgrades; doctor detection strategy.
9. **Filament 4 / Livewire 3 bridge lane** feasibility (P1) — version-gated separate adapter.
10. **Payload fidelity for Filament-specific model shapes**: table records include computed/aggregate attributes and pivot keys (`filament_pivot_key`, array records for non-model data sources); ensure `03` ADR-6 arrays payload restores exactly what Filament reads (`HasRecords.php:150-172,197-208`).
11. **Searchable Select builder propagation** through `Relation::noConstraints()`/`RelationshipJoiner` (`Select.php:1002-1006`).
12. **Driver/cache-store matrix in the Filament lane** (array for logic; Redis/database for realistic latency) and stats collector queue-safety.

## 11. Evidence annex (files inspected—all paths relative to `vendor/`)

**Filament — resources/pages:** `filament/filament/src/Resources/Resource.php:74-110,115-120`; `Resources/Pages/ListRecords.php:28-58,127-137,242-245,260-282`; `Resources/Pages/EditRecord.php`, `CreateRecord.php`, `ViewRecord.php` (page flow); `Resources/Concerns/InteractsWithRelationshipTable.php:19-70,122-129`; `Resources/RelationManagers/RelationManager.php:55-72,127-139,195-199,339-347,352-372`; `Resources/Resource/Concerns/BelongsToTenant.php:20-85,128-218`; `Resources/Resource/Concerns/HasGlobalSearch.php:26-100,125-161,173-264`; `Resources/Resource/Concerns/HasAuthorization.php:28-56`.

**Filament — tables:** `filament/tables/src/Table.php:1-79`; `Table/Concerns/HasQuery.php:28-145,147-187`; `Table/Concerns/HasRecords.php:44-124,156-243`; `Table/Concerns/CanPaginateRecords.php:9-127`; `Table/Concerns/CanBeLazyLoaded.php:7-42`; `Table/Concerns/CanPollRecords.php:7-21`; `Concerns/InteractsWithTable.php:19-200,202-260,262-305,307-357`; `Concerns/HasRecords.php:20-209,211-355`; `Concerns/CanPaginateRecords.php:13-127`; `Concerns/CanSearchRecords.php:14-209,211-385`; `Concerns/CanSortRecords.php:1-153`; `Concerns/HasFilters.php:150-229`; `Concerns/HasBulkActions.php:255-382`; `Concerns/CanReorderRecords.php:20-134`; `Contracts/HasTable.php:20-134`; `resources/views/index.blade.php:153,932-956`; `resources/views/components/search-field.blade.php:1-53`; `Testing/TestsRecords.php:20-86`; `Testing/TestsBulkActions.php:21-294`; `TablesServiceProvider.php:52-57`.

**Filament — widgets/schemas/actions:** `widgets/src/Widget.php:11-119`; `StatsOverviewWidget.php:20-112`; `ChartWidget.php:20-154`; `TableWidget.php:15-52`; `Concerns/CanPoll.php:5-13`; `resources/views/components/widgets.blade.php:37`; `resources/views/chart-widget.blade.php:88-89`; `resources/views/stats-overview-widget.blade.php:5-17`; `widgets/src/Concerns/CanAuthorizeAccess.php`; `actions/src/Action.php`, `Concerns/InteractsWithRecord.php:20-139,302-335`, `Concerns/BelongsToLivewire.php:8-49`, `Concerns/HasLifecycleHooks.php:66-101`, `Concerns/CanFetchSelectedRecords.php:7-35`, `Concerns/InteractsWithSelectedRecords.php:52-75`, `Events/ActionCalled.php:8-20`, `Events/ActionCalling.php`; action files cited in §2.6; `Concerns/InteractsWithActions.php:378-482`; `forms/src/Components/Select.php:474-1071,1071-1153,1615`; `forms/src/Components/Concerns/CanBeSearchable.php:8-80`; `tests` helpers `actions/src/Testing/TestsActions.php:84-144`.

**Filament — panel/tenancy/auth/cache/global search/tests:** `filament/src/Panel.php:1-150`; `Panel/Concerns/HasId.php:11-33`; `Panel/Concerns/HasPlugins.php:8-52`; `Panel/Concerns/HasTenancy.php:146-210,272-298,501-515`; `Panel/Concerns/HasAuth.php:300-303,664-667`; `Panel/Concerns/HasComponents.php:128,228,442,490,714-797`; `Panel/Concerns/HasMiddleware.php:56-129`; `Contracts/Plugin.php:7-14`; `FilamentManager.php:49-75,120-160,385-456,630-643,892-944`; `FilamentServiceProvider.php:72-76,120-174`; `Http/Middleware/SetUpPanel.php:9-20`; `Http/Middleware/IdentifyTenant.php:11-47`; `Http/Middleware/Authenticate.php:10-46`; `Livewire/GlobalSearch.php:11-42`; `GlobalSearch/Providers/DefaultGlobalSearchProvider.php:8-36`; `GlobalSearch/GlobalSearchResults.php`; `Commands/CacheComponentsCommand.php:9-27`; `Commands/ClearCachedComponentsCommand.php:9-27`; `resources/views/livewire/sidebar.blade.php:132`; `resources/views/components/layout/*.blade.php` (livewire tags); `support/src/Concerns/CanBeLazyLoaded.php:8-61`; `support/src/SupportServiceProvider.php:96` (Octane `RequestReceived` → `ComponentManager::resolveScoped()`).

**Livewire:** `livewire/livewire/src/Mechanisms/HandleComponents/HandleComponents.php:20-85,185-264,266-356`; `Component.php:26-169`; `Features/SupportLifecycleHooks/SupportLifecycleHooks.php:9-96`; `Features/SupportComputed/BaseComputed.php:10-175`; `Attributes/Computed.php`, `Attributes/{Locked,Lazy,Defer,Isolate,Renderless}.php` and directory listing (no `Cache.php`); `Features/SupportLockedProperties/BaseLocked.php`; `Features/SupportLazyLoading/{BaseLazy.php,SupportLazyLoading.php:48-90}`; `LivewireManager.php:223-247,293-296`; `Features/SupportTesting/{Testable.php,RequestBroker.php,...}`; `src/helpers.php:97-136`.

**Laravel framework:** `Eloquent/Builder.php:1177-1193` (`paginate`), `1325-1327` (`update`), `1563-1571,1579-1581` (`delete`/`forceDelete`), `2095-2097` (`toBase`); `Query/Builder.php:3681` (`paginate`), `3776-3795` (`getCountForPagination`/`runPaginationCountQuery`); `Eloquent/SoftDeletingScope.php:73-79` (`restore` macro); `Eloquent/SoftDeletes.php:160-200` (instance restore events); `Eloquent/Relations/Concerns/InteractsWithPivotTable.php:333-355,519-550` (attach/detach); `Eloquent/Model.php:2629+` (`resolveRouteBindingQuery`, referenced via `03` §2.2).

**Composer metadata:** `vendor/composer/installed.json` (versions); `filament/filament/composer.json`, `filament/support/composer.json`, `filament/tables/composer.json`, `livewire/livewire/composer.json`; app `composer.json`; `packages/OoMyCache/composer.json:19-35`.
