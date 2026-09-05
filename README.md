# marginfuse/marginfuse

[![Packagist](https://img.shields.io/packagist/v/marginfuse/marginfuse)](https://packagist.org/packages/marginfuse/marginfuse)
[![ci](https://github.com/marginfuse/marginfuse-php/actions/workflows/ci.yml/badge.svg)](https://github.com/marginfuse/marginfuse-php/actions/workflows/ci.yml)
[![license](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Server-side SDK for [MarginFuse](https://marginfuse.com): profitability
guardrails for AI SaaS. Connect revenue to per-request AI cost, see gross margin
per customer, and stop loss-making requests before they run.

- **Metadata only, by construction.** The event shape has no field for prompts
  or responses, so they cannot be sent. Not a policy, an absence.
- **Never breaks your app.** It does not throw into your code, and it does not
  block your request on MarginFuse being up. If MarginFuse is unreachable, your
  requests proceed unchanged.
- **Zero dependencies.** PHP 8.2+, `ext-curl` and `ext-json` only. Nothing in
  your `composer.lock` to conflict with.

> **Server side only.** This SDK carries a secret API key. Never ship it in a
> desktop or mobile application, or anything else a user can read.

## Install

```bash
composer require marginfuse/marginfuse
```

## Track an AI call

Monitoring. One call after each AI request, metadata only.

```php
use MarginFuse\Client;
use MarginFuse\Usage;

$mf = new Client(apiKey: $_ENV['MARGINFUSE_KEY']);

$response = $openai->chat('gpt-4.1', $messages);

$mf->track(
    customerId: 'cus_8x2m91',   // your Stripe customer id, or your own
    provider: 'openai',
    model: 'gpt-4.1',
    feature: 'ai_chat',
    usage: new Usage(
        inputTokens: $response->usage->promptTokens,
        outputTokens: $response->usage->completionTokens,
    ),
);
```

A null property in `Usage` means *not reported*, not "used none": it is left off
the request entirely, because claiming a call used zero input tokens is a
different statement from not knowing what it used.

### How sending works in PHP

PHP has no background threads, so `track()` buffers the event and returns. The
buffer is sent by `flush()`, which is registered to run at shutdown, so in most
applications you never call it.

Under PHP-FPM, shutdown functions run before the response reaches the client. If
you would rather the user not wait for it:

```php
fastcgi_finish_request();   // response is on its way
$mf->flush();               // then the events go
```

In a long-running worker that never shuts down between jobs, call `flush()` at
the end of each job.

## Guard a call

Protection. Ask before the call runs, and act on the answer.

```php
use MarginFuse\Decision;
use MarginFuse\GuardKind;
use MarginFuse\ProviderCall;

$outcome = $mf->guard(
    run: function (Decision $decision) use ($openai, $messages): ProviderCall {
        // $decision->model is the one to call: a downgrade verdict changes it.
        $response = $openai->chat($decision->model, $messages);

        return new ProviderCall(
            usage: new Usage(
                inputTokens: $response->usage->promptTokens,
                outputTokens: $response->usage->completionTokens,
            ),
            result: $response,
        );
    },
    customerId: 'cus_8x2m91',
    provider: 'openai',
    model: 'gpt-4.1',
    feature: 'ai_chat',
);

match ($outcome->kind) {
    GuardKind::Completed => use_result($outcome->result),
    GuardKind::TopupRequired => show_topup($outcome->decision->topupContext),
    GuardKind::Blocked => show_limit_reached(),
};
```

One call does the whole loop: ask, run with the resolved model, report the real
cost, acknowledge what your application did.

### Why a callable

Enforcement must not depend on you remembering to check anything. If `guard`
returned a decision for you to act on, forgetting the check once would mean a
blocked request reaches the provider anyway. With a callable that is
structurally impossible: when the verdict is `Block`, your closure is never
invoked.

### Why decide never throws

There is no failure a caller should branch on. A decision that times out or
errors is an *allow* with `degraded` set, because MarginFuse being unreachable
must never become your outage. Transport failures go to `onError`.

## Tell MarginFuse what a customer pays

Margin needs a revenue side: Stripe for web billing, RevenueCat for App Store
and Google Play proceeds, or declared plan prices. RevenueCat joins by App User
ID; use that same ID in your events. Without a billing connection, declare your
plans in MarginFuse and say which plan each customer is on. Declared revenue
is unverified and does not confirm payment:

```php
$identity = $mf->identify(
    customerId: 'user_8x2m91',
    plan: 'pro',                        // the key of a plan you declared in Settings
    name: 'Acme Studio',
    metadata: ['tier' => 'legacy'],     // labels segment policies can match on
);

if (!$identity->ok) {
    error_log("MarginFuse identify: {$identity->error}");
}
```

Safe to call on every sign-in: sending the plan the customer is already on
changes nothing. Sending a different one ends the current cycle and prorates
what accrued. `periodStart` backdates the cycle for a customer who has been
paying since an earlier date; `clearPlan: true` takes them off plans.

This is the one call that does not fail open. `track()` buffers and `decide()`
allows, because both have a safe default; "I could not record what this
customer pays" has none, and a wrong plan is a wrong margin. So it sends
immediately and reports the failure to you. It still never throws.

`track()`, `guard()` and `decide()` also accept a `plan`, so it can ride along
with usage rather than needing its own call. There it is a hint: a key that
does not resolve is ignored rather than failing your event.

## OpenRouter and other gateways

Gateways report the real cost of every call. Forward it and your figures are
exact instead of estimated.

```php
use MarginFuse\OpenRouter;

$mapped = OpenRouter::from($decoded['usage']);

$mf->track(
    customerId: 'cus_8x2m91',
    provider: 'openrouter',
    model: 'anthropic/claude-sonnet-4.5',
    feature: 'ai_chat',
    usage: $mapped->usage,
    costUsd: $mapped->costUsd,
);
```

Use the helper rather than mapping the fields yourself. OpenRouter's
`prompt_tokens` already includes cached reads and cache writes, which MarginFuse
prices separately, so passing it through directly charges every cached token
twice at the full input rate. The helper also formats the cost as a decimal
string, because PHP renders small floats as `1.2E-7` and the API rejects that.

## Configuration

```php
new Client(
    apiKey: $_ENV['MARGINFUSE_KEY'],
    baseUrl: 'https://api.marginfuse.com',   // your own deployment in dev
    timeout: 1.5,                            // decide budget before failing open
    onError: fn (Throwable $e, string $context) => $logger->warning("marginfuse {$context}", ['exception' => $e]),
);
```

`onError` is the only place transport failures surface. The SDK swallows them so
they cannot become your outage; without the handler they are silent.

### In Laravel

```php
// app/Providers/AppServiceProvider.php
$this->app->singleton(Client::class, fn () => new Client(
    apiKey: config('services.marginfuse.key'),
    onError: fn ($e, $context) => Log::warning("marginfuse {$context}", ['exception' => $e]),
));
```

## What it sends

Everything, and nothing else:

```
eventId  customerId  feature  provider  model  requestedModel  plan
usage { inputTokens, outputTokens, cachedInputTokens,
        cacheCreationTokens, images, audioSeconds }
costUsd  occurredAt  outcome  decisionId  retryOfEventId  correctsEventId
```

There is no field for message content anywhere in the wire types. The
[conformance suite](https://github.com/marginfuse/sdk-contract) checks this
against the bytes that actually leave the process, on every scenario.

## Conformance

This SDK is verified against
[marginfuse/sdk-contract](https://github.com/marginfuse/sdk-contract), the same
contract every MarginFuse SDK in every language is held to. It is a submodule
here, so the pinned commit records exactly which contract a release passed, and
`Contract::VERSION` reports it at runtime.

```bash
git clone --recurse-submodules https://github.com/marginfuse/marginfuse-php
cd marginfuse-php
composer install
composer test          # unit tests, plus the shared gateway vectors
composer analyse       # phpstan, level 9
npm --prefix contract/harness install
npm --prefix contract/harness run conformance php
```

## Links

- [MarginFuse](https://marginfuse.com), product and pricing
- [Documentation](https://marginfuse.com/docs)
- [API reference](https://api.marginfuse.com/openapi.json)
- [Security policy](SECURITY.md)
- [Contributing](CONTRIBUTING.md)

MIT, Pemira Labs.
