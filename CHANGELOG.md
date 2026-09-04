# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0]

### Fixed

- A downgrade that crosses vendors is reported against the vendor that actually
  ran it. `guard()` already ran the model the server chose, but the usage event
  still named the requested provider, so the call was priced from the wrong
  catalog and the saving the downgrade exists to prove was computed against the
  wrong basis. An `allow` is unchanged, because the decision already defaults
  its provider to the requested one.
- A downgrade whose provider call then fails is acknowledged as
  `used_downgrade_model` rather than `proceeded_as_requested`. The cheaper model
  did run; what failed came after. Reporting otherwise told reconciliation the
  policy never applied, which skewed realized-savings attribution on the error
  path.

### Changed

- Pinned contract v2, whose new scenarios cover both corrections above and add
  a privacy check that hands the SDK content-bearing fields and scans the bytes
  that actually leave the process.

## [0.2.0]

### Added

- `identify()`: tell MarginFuse who a customer is and which plan they are on.

  MarginFuse can now compute margin without a revenue source connected, from
  plans you declare in Settings and a plan assigned per customer. This call is
  how your application assigns that plan itself.

  ```php
  $identity = $mf->identify(customerId: 'user_8x2m91', plan: 'pro');
  ```

  `plan` is the key of a plan declared in MarginFuse, not a Stripe price id.
  Safe to call on every sign-in: sending the plan the customer is already on
  changes nothing. `periodStart` backdates the cycle, `clearPlan` ends it.

  Unlike `track()`, this sends immediately and reports failure. A wrong plan is
  a wrong margin, and there is no safe default for "I could not record what
  this customer pays". Check `$identity->ok`; `onError` is called too. It still
  never throws into your code.

- `plan` on `track()`, `guard()` and `decide()`, so a plan can ride along with
  usage rather than needing its own call. There it is a hint: a key that does
  not resolve is ignored rather than failing your event, because usage must
  never be lost to a plan note.

The new parameter is last in every signature, so positional calls written
against 0.1.x keep resolving to the same arguments. Both changes are additive.

## [0.1.1]

### Fixed

- Every guardrail silently failed open on PHP 8.5. The client called
  `curl_close()`, a no-op since PHP 8.0 and deprecated in 8.5. Applications
  that promote deprecations to exceptions, which Symfony's debug handler does,
  turned that notice into a transport failure, so `decide()` reported the
  server as unreachable and fell back to `Allow` even when the server had
  answered `Block`. The guardrails were off and the reason given was false.
  The call is gone; it never did anything on any supported version.

### Changed

- CI runs the test suite and the shared conformance scenarios on PHP 8.5,
  which `composer.json` already declared as supported but nothing exercised.
- The suite now fails on a deprecation or notice, not just a warning. An SDK
  runs inside somebody else's request path and has no business emitting
  diagnostics into it.

## [0.1.0]

First release. PHP 8.2+, no Composer dependencies, `ext-curl` and `ext-json`.

### Added

- `Client::track()` reports an AI call that already happened. Buffers and
  returns immediately, and never throws into application code.
- `Client::decide()` asks whether the next call should run. Fails open to
  `DecisionAction::Allow` with `degraded` set on any timeout or error.
- `Client::guard()` does the whole loop: ask, run your callable with the
  resolved model, report the real cost, acknowledge what the application did.
- `Client::flush()`, registered to run at shutdown, so most applications never
  call it.
- `OpenRouter::from()` maps an OpenRouter usage object, including the gateway's
  own cost, so gateway figures are exact rather than estimated.
- `Contract::VERSION` reports the shared contract this build was verified
  against.

### Notes on the design

- **Buffer plus a shutdown flush, not a background thread.** PHP has none, so
  "does not block your request" means the event is queued rather than sent
  inline. Under FPM you can call `fastcgi_finish_request()` before the flush so
  the user is not waiting for it; the README says so.
- **No Composer dependencies, not even an HTTP client.** A library that requires
  Guzzle or a PSR-18 implementation puts its constraint in every application's
  lock file. curl is an extension, not a package.
- **`guard()` takes a callable.** If it returned a decision to act on,
  forgetting the check once would let a blocked request reach the provider.
- **A null `Usage` property means not reported.** It is omitted from the request
  rather than sent as zero, because those are different claims.
- Analysed at PHPStan level 9, which surfaced a real one: `curl_exec` is typed
  `string|bool` and the code only handled `false`.
- **PHP 8.2, not 8.1.** The value objects are `readonly class`, which is an 8.2
  feature, so the package never worked on 8.1 despite the constraint saying it
  did. 8.1 reached end of life in December 2025, so raising the floor is more
  honest than contorting the types to reach a version nobody should be on.
- Verified against
  [marginfuse/sdk-contract](https://github.com/marginfuse/sdk-contract): 16
  behavioral scenarios and 13 gateway vectors, the same ones the Node, Python,
  Go, Java, .NET and Ruby SDKs pass.
