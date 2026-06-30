# phpdot/psr-bridge

A PSR-3 / Monolog writer for the [PHPdot](https://github.com/phpdot/logs) observability engine.

It implements the engine's `WriterInterface` by forwarding every record to an injected PSR-3 logger (Monolog, or any other). A **peer** backend — it depends only on [contracts](https://github.com/phpdot/contracts) and `psr/log`, never on [tracelog](https://github.com/phpdot/tracelog) — so a Monolog-only app installs `{contracts, logs, psr-bridge}` and never pulls tracelog.

## How records map

- A **log** record is forwarded at its own level; `channel`, `trace_id`, and `span_id` are added to the PSR-3 context so every line carries the trace.
- A finished **span** becomes one line — `span <name>` at `info`, or `error` when the span failed — with kind, duration, attributes, and the trace ids in the context.

No sampling: every record received is forwarded. The only `try/catch` is crash-safety — a misbehaving logger must never bring down the caller — never a drop.

## Usage

Bind it as the engine's `WriterInterface`, pointed at any PSR-3 logger:

```php
use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\PsrBridge\Psr3Writer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$container->set(WriterInterface::class, static fn () =>
    new Psr3Writer(
        (new Logger('app'))->pushHandler(new StreamHandler('php://stdout')),
    ),
);
```

Your packages keep logging against `TracerInterface` — only this binding changes.

## Requirements

- PHP >= 8.4
- psr/log ^3.0
- A PSR-3 logger (e.g. monolog/monolog ^3.0)

## License

MIT
