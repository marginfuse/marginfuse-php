# Contributing

## Getting set up

The conformance contract is a submodule, so clone with it:

```bash
git clone --recurse-submodules https://github.com/marginfuse/marginfuse-php
cd marginfuse-php
composer install
composer test
```

If you already cloned without it: `git submodule update --init --recursive`.

## Before you open a pull request

```bash
composer test
composer analyse
composer lint

npm --prefix contract/harness install
npm --prefix contract/harness run conformance php
```

CI runs all of it on PHP 8.1, 8.2, 8.3 and 8.4.

## Four rules worth knowing before you change behavior

**This SDK never throws into application code.** It sits in the request path of
somebody else's product. A transport error goes to the `onError` handler and the
call proceeds. The one exception is `guard()`, which propagates whatever your own
callable threw, because your error handling owns provider failures.

**`guard()` keeps its callable.** Returning a decision for the caller to act on
reads fine and would be wrong: enforcement would depend on remembering a check,
and forgetting once means a blocked request reaches the provider.

**No Composer dependencies.** Not even an HTTP client. A requirement here lands
in every consuming application's lock file and can conflict with what they
already have. curl is an extension, which is a different thing.

**Behavior is defined in the contract, not here.** The expectations live in
[marginfuse/sdk-contract](https://github.com/marginfuse/sdk-contract) as data,
and every MarginFuse SDK in every language reads the same files. If you are
changing what the SDK does rather than how it does it, the change starts with a
pull request there.

## Style

PHPStan level 9, and php-cs-fixer decides formatting. Comments explain why, not
what. No em dashes.
