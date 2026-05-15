---
name: laravel-design-patterns-toolkit
description: Use when writing Laravel code and unsure which framework pattern to reach for — Policies, Observers, Form Requests, Eloquent attributes/casts/scopes, Service classes, Traits/Concerns, Events/Listeners, Jobs, Artisan Commands, Eloquent Resources. Captures the HOW (how to write each one well) versus the WHEN (covered in laravel-saas-architecture-decisions). The toolkit a senior Laravel dev reaches for daily.
---

# Laravel Design Patterns Toolkit

You are building features in Laravel. This skill is the **how-to manual** for the framework's idiomatic patterns. The companion `laravel-saas-architecture-decisions` covers WHEN to use each (when Repository vs not, when State pattern vs not). This skill covers HOW to write them well once you've decided.

**Origin:** A Laravel 12 SaaS that grew from CRUD controllers to a full state-machine billing system, dunning crons, webhooks, and 30+ Policies. The patterns here are the ones we used most. **None of them is mandatory** — they're tools. Pick the one that fits the problem, not the other way around.

## When to use this skill

Activate when:
- About to add validation to a request → consider Form Request
- About to add auth check to a controller → consider Policy
- About to write `$model->save()` and trigger a side-effect → consider Observer / Event
- About to copy a method between models → consider Trait / Concern
- About to put orchestration logic in a controller > 30 lines → consider Service
- About to query the DB outside of a Model → consider local Scope / Repository
- About to convert raw column data → consider Cast / Accessor
- About to dispatch work that doesn't need immediate response → consider Job
- About to write a CLI command → consider Artisan Command
- About to expose data via API or Inertia → consider Resource / DTO

## Policy — auth at the resource level

**Purpose**: centralize "who can do what" for a model. Replaces scattered `if ($user->role === 'admin')` in controllers.

### The 7 standard methods

```php
final class OrderPolicy
{
    /**
     * Super admin always wins. Other early-return cases here.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->is_super_admin) return true;
        return null;  // null = continue to the specific method
    }

    /** List view — should this user see ANY orders? */
    public function viewAny(User $user): bool { /* ... */ }

    /** Single show — can they see THIS order? */
    public function view(User $user, Order $order): bool { /* ... */ }

    /** Can they create a new order? */
    public function create(User $user): bool { /* ... */ }

    /** Can they edit THIS order? */
    public function update(User $user, Order $order): bool { /* ... */ }

    /** Can they soft-delete THIS order? */
    public function delete(User $user, Order $order): bool { /* ... */ }

    /** (Optional) Can they restore from soft-delete? */
    public function restore(User $user, Order $order): bool { /* ... */ }

    /** (Optional) Can they hard-delete (purge)? */
    public function forceDelete(User $user, Order $order): bool { /* ... */ }
}
```

### Usage in controllers

```php
public function show(Order $order)
{
    $this->authorize('view', $order);  // 403 if denied
    return view('orders.show', compact('order'));
}
```

Or via middleware:
```php
Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('can:view,order');
```

### Tips

- **Register** in `AuthServiceProvider::$policies`. Laravel 12+ auto-discovers if `Order` is in `app/Models/` and `OrderPolicy` is in `app/Policies/`.
- **`viewAny` vs `view`**: `viewAny` is for listing (`/orders`) and answers "should this user have access to this section at all". `view` is for the specific resource.
- **Avoid duplicate logic**: if `update` and `delete` have the same gate, write a private helper: `private function canManage(User $user, Order $order): bool`.
- **Multi-tenant**: combine `BelongsToTenant` global scope (filters the list) with the Policy (filters specific actions). The global scope is automatic; the Policy is explicit.

## Observer — react to model lifecycle events

**Purpose**: side-effects on model events (created, updated, deleted) WITHOUT polluting the model itself.

```php
namespace App\Observers;

final class OrderObserver
{
    public function created(Order $order): void
    {
        // Auto-generate invoice number, send notification, etc.
        $order->update(['number' => InvoiceNumberGenerator::next($order->tenant_id)]);
    }

    public function updating(Order $order): void
    {
        // Validate transition: pending → paid OK, paid → pending NOT OK
        if ($order->isDirty('status') && ! $this->isValidTransition($order)) {
            throw new InvalidOrderTransitionException();
        }
    }

    public function deleting(Order $order): void
    {
        // Cleanup related — but prefer DB cascades when possible
        Cache::forget("order:{$order->id}");
    }
}
```

Register in `AppServiceProvider::boot()`:
```php
Order::observe(OrderObserver::class);
```

### Tips

- **Use `static::booted()` in the model for simple cases** — observers are for cross-cutting / non-obvious behavior.
- **Order of execution**: `creating` (before insert) → `created` (after insert). `updating` → `updated`. `saving` (both create + update before) → `saved` (after).
- **Bypass observers** with `Model::withoutEvents(fn () => Model::create([...]))`. Use rarely — usually a smell.
- **Don't put validation in Observers** — use Form Requests or model validation. Observers fire even from tinker.
- **Idempotent**: an Observer can fire multiple times if the model is re-saved. Make side-effects idempotent (check before send, check before generate).

## Form Request — validation + authorization in one place

**Purpose**: extract complex validation from controllers. Replaces inline `$request->validate([...])`.

```php
namespace App\Http\Requests;

final class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Order::class);
    }

    public function rules(): array
    {
        return [
            'customer_id'   => ['required', 'integer', Rule::exists('customers', 'id')->where('tenant_id', $this->user()->current_tenant_id)],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'items.*.quantity'   => ['required', 'integer', 'min:1'],
            'note'          => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.exists' => 'Customer not found in your business.',
            'items.required'     => 'Order must have at least one item.',
        ];
    }

    /**
     * Optional: transform validated data into a DTO before passing to service.
     */
    public function toDto(): CreateOrderDto
    {
        return new CreateOrderDto(
            customerId: $this->integer('customer_id'),
            items: collect($this->input('items'))->map(fn ($i) => new OrderItemDto(...$i))->all(),
            note: $this->input('note'),
        );
    }
}
```

Usage:
```php
public function store(StoreOrderRequest $request, OrderService $service): RedirectResponse
{
    $order = $service->create($request->toDto());
    return redirect()->route('orders.show', $order);
}
```

### Tips

- **`authorize()` returning false = 403** — combine with Policy for consistency: `return $this->user()->can('create', Order::class);`.
- **Custom messages**: place in `messages()`. For dynamic messages (with placeholders), use `:attribute`.
- **`prepareForValidation()`**: massage input before validation (e.g. trim, lowercase email).
- **`passedValidation()`**: hook after validation passes — but DON'T put business logic here, put it in the service.
- **`toDto()` pattern**: keeps controllers thin. Service receives a typed DTO, not raw arrays.

## Eloquent Attributes — casts, accessors, mutators

### `$casts` — auto-convert column values

```php
final class Order extends Model
{
    protected $casts = [
        'status'      => OrderStatus::class,        // enum
        'total_cents' => 'integer',
        'metadata'    => 'array',                    // JSON column ↔ PHP array
        'paid_at'     => 'immutable_datetime',       // CarbonImmutable, not Carbon
        'card_token'  => 'encrypted',                // auto encrypt/decrypt
        'is_paid'     => 'boolean',
    ];
}
```

**Always use `immutable_datetime`** instead of `datetime` — Carbon mutations are footguns (`$order->paid_at->addDay()` modifies in-place).

**`encrypted` cast** for tokens — combined with `$hidden` to prevent serialization leak.

### Accessor / Mutator — PHP 8+ Attribute style

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

public function fullName(): Attribute
{
    return Attribute::make(
        get: fn ($value, $attributes) => trim("{$attributes['first_name']} {$attributes['last_name']}"),
        set: fn ($value) => ['first_name' => explode(' ', $value)[0], 'last_name' => explode(' ', $value)[1] ?? ''],
    );
}

public function totalDollars(): Attribute
{
    return Attribute::make(
        get: fn ($value, $attributes) => $attributes['total_cents'] / 100,
    )->shouldCache();  // memoize the accessor for the request lifetime
}
```

### Local Scopes

```php
public function scopePaid(Builder $q): Builder
{
    return $q->whereNotNull('paid_at');
}

public function scopeForCustomer(Builder $q, Customer $customer): Builder
{
    return $q->where('customer_id', $customer->id);
}

// Usage:
Order::paid()->forCustomer($customer)->get();
```

### Global Scopes — for multi-tenant + soft delete

```php
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $b) {
            if (app()->bound('current_tenant')) {
                $b->where($b->getModel()->getTable() . '.tenant_id', app('current_tenant')->id);
            }
        });
    }
}
```

**Bypass with `Model::withoutGlobalScope('tenant')` or `withoutGlobalScopes()` — only in Repositories that explicitly check access**.

## Service classes — orchestration > 30 lines

**Purpose**: extract multi-step business logic from controllers / models.

```php
namespace App\Services;

final class OrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly NotificationService $notifications,
    ) {}

    public function create(CreateOrderDto $dto): Order
    {
        return DB::transaction(function () use ($dto) {
            $this->inventory->reserveStock($dto->items);

            $order = Order::create([
                'tenant_id'   => app('current_tenant')->id,
                'customer_id' => $dto->customerId,
                'total_cents' => $this->calculateTotal($dto->items),
                'note'        => $dto->note,
            ]);

            foreach ($dto->items as $item) {
                $order->items()->create($item->toArray());
            }

            event(new OrderCreated($order));
            return $order;
        });
    }

    public function markAsPaid(Order $order, int $amountCents): void { /* ... */ }

    private function calculateTotal(array $items): int { /* ... */ }
}
```

### Tips

- **Constructor DI** with `readonly`. Never use the container inside methods (`app(InventoryService::class)`).
- **`final class`** by default — open to inheritance only with explicit reason.
- **Single responsibility**: one Service per business concept (`OrderService`, `RefundService`, `InventoryService`). Don't make a `BusinessLogicService` god class.
- **Transactions wrap multi-step** writes. Forgetting `DB::transaction()` is the #1 bug source in Services.
- **Return rich types**, not arrays. Return `Order`, not `['id' => ..., 'total' => ...]`.

## Traits / Concerns — shared behavior across models

```php
namespace App\Models\Concerns;

trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }
}
```

Apply: `class Order extends Model { use HasUuid; }`.

### Tips

- **Naming**: `Has*` (HasUuid, HasSlug), `Belongs*` (BelongsToTenant), `Is*` (IsCancellable). Reads naturally in `use` statement.
- **`bootXxx` and `bootedXxx`**: Laravel auto-calls `bootHasUuid()` if the trait is named `HasUuid` and added to a Model. Use `booted` for cleaner ordering (runs after constructor).
- **Don't put state in traits** — traits are stateless behaviors. If you need state, use a class.
- **Avoid trait soup**: `class Order extends Model { use A, B, C, D, E, F; }` is a red flag. Combine or refactor to Service.

## Events / Listeners — decoupled side-effects

```php
// Event (immutable carrier)
namespace App\Events;
final readonly class OrderCreated
{
    public function __construct(public Order $order) {}
}

// Listener
namespace App\Listeners;
final class SendOrderConfirmation
{
    public function __construct(private MailService $mail) {}

    public function handle(OrderCreated $event): void
    {
        $this->mail->send($event->order->customer, new OrderConfirmation($event->order));
    }
}
```

Register in `EventServiceProvider::$listen`:
```php
protected $listen = [
    OrderCreated::class => [
        SendOrderConfirmation::class,
        UpdateAnalytics::class,
        NotifyKitchen::class,
    ],
];
```

### Tips

- **Events are nouns** (`OrderCreated`, `PaymentReceived`), Listeners are verbs (`SendOrderConfirmation`).
- **Make Listeners queueable** (`implements ShouldQueue`) for heavy work (emails, notifications, API calls). Lightweight listeners stay sync.
- **One Event, multiple Listeners** is the power. Don't bury 4 side-effects in one Listener — split them.
- **Don't pass DB queries through events** — pass model instances or scalars. Events serialize for queues; queries don't.
- **Domain Events** (`SubscriptionRenewed`, `OrderShipped`) sit in `app/Domain/<Context>/Events/` for clean bounded contexts.

## Jobs — async work

```php
final class GenerateInvoicePdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [10, 30, 60];  // exponential

    public function __construct(public Invoice $invoice) {}

    public function handle(InvoicePdfService $service): void
    {
        $path = $service->generate($this->invoice);
        $this->invoice->update(['pdf_path' => $path]);
        event(new InvoicePdfReady($this->invoice));
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('billing')->error('invoice_pdf_failed', [
            'invoice_id' => $this->invoice->id,
            'error' => $e->getMessage(),
        ]);
    }
}
```

Dispatch: `GenerateInvoicePdf::dispatch($invoice);` or `dispatch_sync` for synchronous in tests.

### Tips

- **`SerializesModels`** stores only the ID, re-fetches on `handle()`. Avoid passing large arrays — pass IDs.
- **`$tries` + `backoff`** for transient failures (HTTP, DB locks).
- **`failed()`** hook for terminal failure logging + alerting.
- **Queue connection per concern**: `kitchen` queue for thermal printing, `mail` for emails, `default` for everything else. Worker config matches: `php artisan queue:work --queue=kitchen,mail,default`.
- **Idempotent**: a Job can run twice if the worker dies mid-way. Make `handle()` idempotent (check state, dedupe on key).
- **No Inertia / HTTP context inside Jobs** — they run in a fresh container with no session.

## Artisan Commands — CLI for ops + crons

```php
namespace App\Console\Commands;

final class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'billing:reconcile-subscriptions
                            {--tenant= : Reconcile only this tenant ID}
                            {--dry-run : Report discrepancies without applying}';

    protected $description = 'Reconcile our subscription state against the payment gateway.';

    public function handle(SubscriptionRepository $repo, PaymentGatewayInterface $gateway): int
    {
        $subscriptions = $this->option('tenant')
            ? $repo->forTenant((int) $this->option('tenant'))
            : $repo->all();

        $discrepancies = 0;
        $this->withProgressBar($subscriptions, function ($sub) use ($gateway, &$discrepancies) {
            $remote = $gateway->getSubscription($sub->gateway_subscription_id);
            if ($remote && $remote->status !== $sub->status->value) {
                $discrepancies++;
                if (! $this->option('dry-run')) {
                    // apply correction
                }
            }
        });

        $this->newLine();
        $this->info("Discrepancies found: {$discrepancies}");
        return self::SUCCESS;
    }
}
```

### Tips

- **Signature format**: `--flag` for booleans, `--key= : description` for options, `{arg}` for required positionals.
- **`return self::SUCCESS / FAILURE`** — for CI/cron exit codes.
- **`--dry-run` everywhere** — let ops simulate before applying.
- **Output**: `$this->info()` for normal, `$this->warn()` for warnings, `$this->error()` for failures. JSON output via `--format=json` for ops dashboards.
- **Idempotent commands**: re-running should be safe.
- **Schedule** in `app/Console/Kernel.php`:
  ```php
  $schedule->command('billing:reconcile-subscriptions')->dailyAt('07:00');
  ```

## Eloquent Resources — API / Inertia output shaping

```php
final class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'number'       => $this->number,
            'status'       => $this->status->value,
            'total_cents'  => $this->total_cents,
            'currency'     => $this->currency,
            'created_at'   => $this->created_at->toIso8601String(),
            'customer'     => CustomerResource::make($this->whenLoaded('customer')),
            'items'        => OrderItemResource::collection($this->whenLoaded('items')),
            // Conditional fields
            'card_brand'   => $this->when($request->user()?->is_super_admin, $this->card_brand),
        ];
    }
}
```

### Tips

- **`whenLoaded()`** = only include if the relation is eager-loaded. Prevents N+1 leaks via Resources.
- **`when()`** for conditional fields (auth-based, role-based).
- **API endpoints**: use Resources for public/external APIs (versioning, contract).
- **Inertia pages**: prefer `array` returns from controllers for simplicity. Use Resources for the shared shape consumed by multiple pages.
- **`additional()` for envelope**:
  ```php
  return OrderResource::collection($orders)->additional(['meta' => ['count' => $orders->count()]]);
  ```

## Value Objects — primitive obsession antidote

```php
final readonly class Money
{
    public function __construct(
        public int $amountCents,
        public string $currency,
    ) {
        if ($amountCents < 0) throw new InvalidArgumentException('Money cannot be negative');
        if (strlen($currency) !== 3) throw new InvalidArgumentException('Currency must be ISO 4217');
    }

    public function add(Money $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException();
        }
        return new self($this->amountCents + $other->amountCents, $this->currency);
    }

    public function format(string $locale = 'en_US'): string
    {
        return (new NumberFormatter($locale, NumberFormatter::CURRENCY))
            ->formatCurrency($this->amountCents / 100, $this->currency);
    }
}
```

### Tips

- **`final readonly class`** — VOs are immutable by definition.
- **Equality**: implement `equals(self $other): bool` for comparison.
- **No setters** — return a new instance for modifications.
- **Cast in Eloquent** with a custom cast:
  ```php
  protected $casts = ['total' => MoneyCast::class];
  ```

## DTOs — data transfer between layers

```php
// app/DataTransferObjects/CreateOrderDto.php
final readonly class CreateOrderDto
{
    public function __construct(
        public int $customerId,
        /** @var OrderItemDto[] */
        public array $items,
        public ?string $note = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            customerId: $request->integer('customer_id'),
            items: array_map(fn ($i) => new OrderItemDto(...$i), $request->input('items', [])),
            note: $request->string('note')->value() ?: null,
        );
    }
}
```

Or use **Spatie laravel-data** if you want validation + transformation in one class:

```php
final class CreateOrderData extends Data
{
    public function __construct(
        #[Required, Exists('customers', 'id')]
        public int $customerId,

        #[DataCollectionOf(OrderItemData::class)]
        public DataCollection $items,

        #[Max(500)]
        public ?string $note = null,
    ) {}
}
```

## Cheat sheet — pattern decision

| You want to... | Reach for... |
|---|---|
| Validate input + authorize | Form Request |
| Decide who can do what | Policy |
| React when a model saves | Observer or `static::booted()` |
| Share methods across models | Trait / Concern |
| Multi-step business logic | Service |
| Decouple side-effects | Event + Listener |
| Async heavy work | Job |
| CLI / cron command | Artisan Command |
| Shape data for output | Eloquent Resource |
| Transfer data between layers | DTO (readonly) |
| Encapsulate money/email/id | Value Object (readonly) |
| Reusable query filter | Local Scope |
| Auto-filter every query | Global Scope (in Trait) |
| Convert DB column to/from PHP | Cast |
| Computed property | Accessor (Attribute::make) |

## Anti-patterns — never do this

- **Putting business logic in controllers > 30 lines** — extract to Service
- **Putting business logic in Models** — Eloquent Models hold attributes + relationships, not orchestration
- **Putting validation in Observers** — Observers fire from anywhere (tinker, jobs). Use Form Requests.
- **Service-locator pattern**: `app(InventoryService::class)` inside a class method — use constructor DI
- **God Service**: `BusinessLogicService` with 40 methods — split by concept
- **Using `$guarded = []`** on any model — explicit `$fillable` is non-negotiable
- **Trait soup**: a model with 7+ traits — refactor to composition
- **Events that carry queries**: `event(new X(Order::where(...)))` — pass the model, not the query
- **Jobs that don't handle failure**: missing `failed()` hook → silent failures in prod
- **Jobs without `tries` / `backoff`**: transient errors retry infinitely or not at all
- **Inertia pages using Resources for everything**: Resources serialize on every page load; arrays are faster for simple shapes
- **Mutating Carbon dates from accessors**: use `immutable_datetime` cast
- **Hardcoding strings instead of enums**: `'paid'` everywhere → `OrderStatus::Paid` always

## Cross-references

- `laravel-saas-architecture-decisions` — WHEN to use each pattern (vs how, covered here)
- `laravel-saas-multi-tenant-foundation` — Global Scopes, BelongsToTenant trait
- `laravel-saas-auth-granularity` — Policy patterns for Owner vs Branch Manager
- `laravel-saas-billing-infrastructure` — State pattern + Idempotency + Jobs (real implementation reference)
- `senior-dev-code-style` — naming, immutability, DI doctrine
