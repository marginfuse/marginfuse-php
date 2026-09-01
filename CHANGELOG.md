# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project
follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
