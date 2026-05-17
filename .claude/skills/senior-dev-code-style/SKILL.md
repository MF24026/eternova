---
name: senior-dev-code-style
description: Use ALWAYS when writing or reviewing code in any project. This is the cross-cutting style guide that separates junior code (works but ages badly) from senior code (works, ages well, reads in 6 months). Covers naming, comment doctrine, method length, dependency injection, immutability, error messages, test naming, no premature abstraction. Language-agnostic principles with PHP/Laravel examples. Read this BEFORE writing any non-trivial code.
---

# Senior Dev Code Style

You are writing code that another person — possibly you in 6 months — will read, debug, and extend. This skill captures the cross-cutting writing style that distinguishes senior code from working-but-aging code. **None of these rules are syntactic.** They're about decisions you make every line.

**Origin:** Lessons from a Laravel SaaS that grew from 100 to 1100 tests, from 5 to 50 modules, with one solo dev. Each rule here corresponds to a moment where bad style caused a real bug or a real "what was I thinking" moment. **Read this BEFORE writing code, not after.**

## When to use this skill

ALWAYS, on every non-trivial piece of code. Specifically:
- Writing a new method, class, or function
- Reviewing your own code before commit
- Renaming things
- Adding a comment (consider: don't)
- Adding error handling (consider: do you actually need it)
- Adding a parameter (consider: should this be 2 methods)
- Designing test cases (consider: how do they read)
- Replying to a code review

## The doctrines (in priority order)

### 1. Names describe behavior, not implementation

```php
// BAD: tells you HOW
function arrayFilterByX($arr, $x) { ... }
function loopThroughOrdersAndSum($orders) { ... }

// GOOD: tells you WHAT
function ordersFromLastMonth(): array { ... }
function totalRevenue(): int { ... }
```

**Rule**: if removing the method name and reading only the parameters tells you what it does, the name is wrong. If you'd need to read the implementation to understand the call site, the name is wrong.

**Method names are verbs**: `calculate*`, `find*`, `markAs*`, `requireAuthorized*`. **Class names are nouns**: `Order`, `OrderRepository`, `PriceCalculator`. **Boolean methods are predicates**: `isPaid()`, `canCancel()`, `hasOverride()`.

### 2. Comments only for the WHY, never the WHAT

```php
// BAD: WHAT (the code already says this)
// Increment the counter
$counter++;

// Loop through users
foreach ($users as $user) { ... }

// BAD: temporal / PR-relative reference (ages badly)
// Used by the new checkout flow we added in v2.3
// TODO: remove after we migrate everything

// GOOD: WHY (non-obvious constraint, hidden invariant)
// MySQL UNIQUE indexes treat NULL as distinct, so we need to enforce
// "one default per tenant" at the application layer via Branch::booted()
$branch->is_default = true;

// GOOD: explains the subtle decision
// We use immutable_datetime here so that downstream code can't mutate
// the original $order->paid_at by chaining ->addDay() etc.
protected $casts = ['paid_at' => 'immutable_datetime'];
```

**Rule**: before writing a comment, ask: "Would a competent dev be confused without this?" If no → delete. If yes → write the comment.

**Comments that almost always age badly**:
- `// Used by X` (X moves or gets renamed)
- `// Added for issue #123` (belongs in commit message)
- `// TODO: refactor this` (will live there for 3 years)
- `// Quick hack` (3 years later you're scared to touch it)
- `// HACK: ` (same)

### 3. Method length: one concept per method

**Rule of thumb**: < 30 lines. If it's longer, you're probably doing 2 things.

```php
// BAD: 3 concepts in one method
function processOrder(Order $order)
{
    // Validate
    if (!$order->customer) throw ...;
    if ($order->items->isEmpty()) throw ...;

    // Calculate
    $subtotal = $order->items->sum(fn($i) => $i->price * $i->qty);
    $tax = $subtotal * 0.16;
    $total = $subtotal + $tax;

    // Persist
    $order->update(['total' => $total]);
    foreach ($order->items as $item) { ... }
}

// GOOD: each concept named
function processOrder(Order $order): void
{
    $this->validate($order);
    $total = $this->calculateTotal($order);
    $this->persist($order, $total);
}
```

You don't have to inline-extract for the sake of small methods — but if your method has section comments ("// Validate", "// Calculate"), those sections want to be methods.

### 4. Dependency injection in the constructor, not the container in methods

```php
// BAD: service locator anti-pattern
class OrderService
{
    public function create(array $data): Order
    {
        $inventory = app(InventoryService::class);  // hidden dep
        $inventory->reserveStock(...);
    }
}

// GOOD: explicit dependency
final class OrderService
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function create(array $data): Order
    {
        $this->inventory->reserveStock(...);
    }
}
```

**Why**: explicit deps make the class **testable** (mock by constructor), **introspectable** (read the constructor to know what it needs), **decoupled** (no global state).

### 5. Immutability by default

```php
// BAD: mutable
class OrderTotal
{
    public int $cents;
    public string $currency;
}
$total = new OrderTotal();
$total->cents = 1000;
// 3 hours later, $total->cents = 5000 from some side-effect

// GOOD: immutable
final readonly class OrderTotal
{
    public function __construct(
        public int $cents,
        public string $currency,
    ) {}

    public function withCents(int $cents): self
    {
        return new self($cents, $this->currency);
    }
}
$total = new OrderTotal(1000, 'USD');
$bigger = $total->withCents(5000); // $total is unchanged
```

**Why**: mutable state is the source of "I have no idea why this value changed" bugs. Immutability makes data flow obvious.

**Apply to**: Value Objects, DTOs, Event payloads, configuration objects. NOT to Eloquent Models (they're mutable by design — Models are entities, not values).

### 6. Early return, no nested if

```php
// BAD: nested
function chargeCard($order, $user)
{
    if ($user) {
        if ($user->active) {
            if ($order->canCharge()) {
                if ($this->gateway->isUp()) {
                    return $this->gateway->charge(...);
                } else {
                    return 'gateway down';
                }
            } else {
                return 'order cannot charge';
            }
        } else {
            return 'user inactive';
        }
    } else {
        return 'no user';
    }
}

// GOOD: early return
function chargeCard(?User $user, Order $order): ChargeResult
{
    if (! $user) return ChargeResult::failed('no_user');
    if (! $user->active) return ChargeResult::failed('user_inactive');
    if (! $order->canCharge()) return ChargeResult::failed('order_not_chargeable');
    if (! $this->gateway->isUp()) return ChargeResult::failed('gateway_down');

    return $this->gateway->charge($order);
}
```

**Why**: nesting > 2 levels is a reading hazard. Early returns make the happy path the indentation-zero path.

### 7. Magic strings → typed enums always

```php
// BAD
if ($order->status === 'paid') { ... }
$order->status = 'cancelled';

// GOOD
enum OrderStatus: string {
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
if ($order->status === OrderStatus::Paid) { ... }
$order->status = OrderStatus::Cancelled;
```

**Why**: enums catch typos at compile time, enable exhaustive match, document the universe.

### 8. No premature abstraction

```php
// BAD: 3 similar lines extracted into an abstraction "for reuse later"
abstract class BasePresenter { /* 50 lines */ }
class OrderPresenter extends BasePresenter {}

// GOOD: 3 similar lines kept as 3 similar lines until there's a 4th case
// (and even then, consider if duplication is OK)
```

**Rule of three**: don't extract a shared abstraction until you have 3 concrete use cases. 2 is coincidence. 3 is pattern.

**Why**: premature abstractions are harder to delete than duplication. They constrain future code by their (often wrong) assumptions about the third case.

### 9. Type hints over comments

```php
// BAD: comment says the contract, type doesn't
/**
 * @param int $id
 * @param string|null $note
 * @return Order
 */
function findOrder($id, $note = null) { ... }

// GOOD: type IS the contract
function findOrder(int $id, ?string $note = null): Order { ... }
```

**Why**: types are checked. Comments lie silently.

**Use phpDoc only when**:
- The type is too complex for the type system (`array<int, string>`)
- The behavior is non-obvious (constraints not in the signature)
- It's a public API and you want IDE hover hints

### 10. Error messages are for the human reading the failure

```php
// BAD: useless to the user, useless to the debugger
throw new \Exception('Error');
throw new \Exception('failed');
throw new \RuntimeException('null');

// BAD: useful to the debugger, leaks to the user
throw new \Exception("Database query failed: SELECT * FROM users WHERE id={$id} returned null");

// GOOD: actionable, specific, safe to surface
throw new \DomainException(
    "Cannot cancel a paid order. Order #{$order->number} was paid on {$order->paid_at}. " .
    "Use issueRefund() instead."
);
```

**Rule**: an error message should answer (a) WHAT happened, (b) WHY it's an error, (c) WHAT to do instead. None of that is the user's PII.

### 11. Test names describe the scenario, not the method

```php
// BAD
public function test_create() { ... }
public function test_charge_works() { ... }

// GOOD
public function test_cannot_cancel_order_after_payment_received() { ... }
public function test_owner_can_revert_branch_override_but_manager_cannot() { ... }
public function test_charge_with_expired_card_returns_decline_not_throw() { ... }
```

**Rule**: read the test name out loud. If it sounds like a sentence describing behavior, good. If it sounds like a method name, bad.

### 12. Don't catch what you don't handle

```php
// BAD: catches and re-throws nothing useful
try {
    $this->gateway->charge($order);
} catch (\Exception $e) {
    Log::error($e->getMessage());
    throw new \Exception('Charge failed');  // loses original context
}

// GOOD: specific catch, useful handling
try {
    $this->gateway->charge($order);
} catch (NetworkException $e) {
    // Specific recoverable error → retry later
    $this->scheduleRetry($order);
    return ChargeResult::pending($order);
} catch (CardDeclinedException $e) {
    // Specific terminal error → user-facing
    return ChargeResult::failed($e->declineCode());
}
// Other exceptions bubble up — bug or unknown state, fail loudly
```

**Rule**: catch only what you can recover from. Let bugs surface — don't bury them in a generic `catch (\Exception $e)` block.

### 13. Pure functions for calculations, side-effects in services

```php
// BAD: calculation tangled with persistence
class OrderService
{
    public function applyDiscount(Order $order, Discount $d): int
    {
        $discounted = $order->total - $d->amount;
        $order->update(['total' => $discounted]);  // side effect!
        return $discounted;
    }
}

// GOOD: pure calc + explicit persist
class PriceCalculator
{
    public function applyDiscount(int $total, Discount $d): int
    {
        return max(0, $total - $d->amount);  // pure
    }
}
class OrderService
{
    public function applyDiscount(Order $order, Discount $d): void
    {
        $newTotal = $this->calculator->applyDiscount($order->total, $d);
        $order->update(['total' => $newTotal]);
    }
}
```

**Why**: pure functions are trivially testable. Side-effect functions need test setup. Separate them so calculations are testable in isolation.

### 14. Named arguments for clarity (PHP 8+)

```php
// BAD: what does true mean here?
$order = createOrder($customer, $items, true, false, 'COP', null);

// GOOD: each argument is self-documenting
$order = createOrder(
    customer: $customer,
    items: $items,
    sendNotification: true,
    skipInventoryCheck: false,
    currency: 'COP',
    note: null,
);
```

**Rule**: if a method has > 2 parameters AND any of them is a boolean or has a non-obvious type, use named arguments at the call site.

**At the definition site**: prefer DTOs over long parameter lists. 5+ params is a smell.

### 15. Booleans tell a story; flags don't

```php
// BAD: what does the caller mean?
$service->process($order, true, false, true);

// SOMEWHAT GOOD: named args help
$service->process($order, dryRun: true, sendEmail: false, notifyKitchen: true);

// BETTER: split into multiple methods
$service->dryRunProcess($order);
$service->processWithoutEmail($order, notifyKitchen: true);
```

**Rule**: if a boolean flag changes the method's behavior significantly, it should probably be 2 methods.

### 16. Return rich types, not arrays

```php
// BAD: array shape is implicit, easy to break
public function charge(Order $order): array
{
    return [
        'success' => true,
        'transaction_id' => 'tx_123',
        'amount' => 1000,
    ];
}

// GOOD: explicit type
final readonly class ChargeResult
{
    public function __construct(
        public bool $success,
        public ?string $transactionId,
        public int $amountCents,
        public ?string $errorCode,
    ) {}

    public static function succeeded(string $txId, int $amountCents): self { ... }
    public static function failed(string $errorCode): self { ... }

    public function isSuccess(): bool { return $this->success; }
}

public function charge(Order $order): ChargeResult { ... }
```

**Why**: typed returns are introspectable, IDE-completable, refactor-safe. Arrays are guess-the-shape.

### 17. Pick a side: framework or domain

When extending framework classes (Eloquent Model, Form Request, Job), embrace the framework idioms. When writing domain logic (state machines, calculators, value objects), use pure PHP/typed code.

**Don't mix**: a Model with 30 methods of business logic is a god class. Push business logic into Services / Value Objects. Keep Models thin: attributes, relationships, simple accessors.

### 18. Consistency over preference

The "correct" style is the style the rest of the codebase uses. If the project uses `final class` everywhere, your new class should be `final`. If it uses `readonly` DTOs, yours should be too. **Don't introduce your personal taste inconsistently** — it pollutes the codebase.

When you DO want to introduce a new convention: document it in CLAUDE.md or equivalent, then apply it consistently going forward (don't refactor everything).

## Specific anti-patterns

- **Yoda conditions**: `if (5 === $x)` — PHP doesn't allow assignment-in-condition errors that this prevents. Use `if ($x === 5)`.
- **`else` after `return`**: `if (x) return A; else return B;` → `if (x) return A; return B;`
- **Single-letter variable names** except in tight loops: `foreach ($users as $u)` is fine; `function f($a, $b, $c)` is not.
- **Encoding type in variable name (Hungarian)**: `$strName`, `$arrItems` — types are types, names are meanings. `$name`, `$items`.
- **Catching `\Throwable`** to "make tests green" — you're hiding bugs.
- **Returning `null` to mean "not found" AND "error"** — distinct cases need distinct signals (null vs exception, or a Result type).
- **Mutating method parameters**: passing arrays by reference and modifying. Return a new value.
- **Hiding side-effects**: a method named `getX()` that also writes to DB. If it has side-effects, name it `loadAndCacheX()`.
- **Long parameter lists** (>4 args) without named arguments or a DTO.
- **Boolean parameters that toggle behavior** (use 2 methods or a typed enum).
- **Commented-out code "in case we need it"** — git remembers. Delete.
- **Defensive coding for impossible cases**: validating a parameter that the type system already guarantees.

## The senior dev review checklist (5-minute self-review before commit)

Before pushing:

1. **Read your diff with fresh eyes.** Does each change make sense without conversation context?
2. **Are there magic strings?** Convert to enum or constant.
3. **Are there comments?** Each one: does it explain WHY non-obvious? If not, delete.
4. **Are there methods > 30 lines?** Can you extract a named concept?
5. **Are there `else` clauses?** Often a sign you can early-return.
6. **Are exceptions specific or generic?** Specific exceptions = caller can react.
7. **Are types complete?** Every param + return typed. Use `Attribute::make` for accessors.
8. **Are tests named as sentences?** Read them out loud.
9. **Is there a leak?** Search the diff for: tokens, PAN, passwords, request bodies in logs/exceptions.
10. **Is the variable named for its meaning, not its source?** `$users` not `$usersFromDb`.

## When to break these rules

Rules are heuristics. Break them with intent:
- A 50-line method that's a state machine with 9 cases is OK — splitting it loses cohesion.
- A god class IS OK if it's `php artisan` (one entry, one purpose) or a generated config.
- Long parameter lists ARE OK for low-level constructors (gateway clients).
- A redundant comment IS OK when the file is going to be onboarded by a non-Laravel dev.

**Senior dev = knows when to break the rules and why. Junior dev = follows the rules blindly or breaks them randomly.**

## Cross-references

- `laravel-design-patterns-toolkit` — the Laravel patterns these doctrines apply to
- `laravel-saas-architecture-decisions` — when to use which pattern at all
- `saas-testing-dual-layer` — test naming + structure
- `laravel-saas-billing-infrastructure` — concrete examples of pro-grade discipline applied
