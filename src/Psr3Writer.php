<?php

declare(strict_types=1);

/**
 * PSR-3 Writer
 *
 * The PSR-3 backend for the phpdot observability engine: it implements the
 * engine's {@see WriterInterface} export boundary by forwarding every record to an
 * injected PSR-3 {@see LoggerInterface} (e.g. Monolog). A peer writer — it owns no
 * trace identity and depends only on the contracts + `psr/log`, never on
 * tracelog, so a Monolog-only application never installs tracelog.
 *
 * A log record is forwarded at its own level; a finished span is forwarded as a
 * single log line (`span <name>`) at info, or error when the span failed. The
 * trace/span correlation ids are always added to the PSR-3 context so every line
 * carries the trace id.
 *
 * A record flagged `secure()` has its message AND context encrypted with the
 * injected {@see EncryptorInterface} and forwarded as ciphertext; it is fail-closed
 * — dropped, never forwarded in plaintext — when no encryptor is configured.
 *
 * NO sampling: every other record is forwarded. The only `try/catch` is
 * crash-safety — a misbehaving logger must not bring down the caller or the
 * coroutine-end span flush.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\PsrBridge;

use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Logs\EncryptorInterface;
use PHPdot\Contracts\Logs\WriterInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

#[Singleton]
final class Psr3Writer implements WriterInterface
{
    /** The PSR-3 levels, lowest to highest, used to validate the engine's level token. */
    private const array LEVELS = [
        LogLevel::DEBUG,
        LogLevel::INFO,
        LogLevel::NOTICE,
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY,
    ];

    /**
     * @param LoggerInterface $logger The PSR-3 logger every record is forwarded to.
     * @param EncryptorInterface|null $encryptor Optional encryptor for records flagged secure().
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?EncryptorInterface $encryptor = null,
    ) {}

    /**
     * Forward a record — a log line or a finished span — to the PSR-3 logger.
     *
     * @param array<string, mixed> $record The engine record to export.
     */
    public function write(array $record): void
    {
        // The only try/catch is crash-safety — a logger failure must not crash the
        // caller. A secure() record is encrypted, or fail-closed (dropped, never
        // plaintext) when no encryptor is configured; every other record is forwarded.
        try {
            if ($this->isSensitive($record)) {
                $this->writeSensitive($record);

                return;
            }

            if (($record['type'] ?? null) === 'span') {
                $this->writeSpan($record);

                return;
            }

            $this->writeLog($record);
        } catch (Throwable) {
            // Intentionally swallowed — logging/tracing must not bring down the caller.
        }
    }

    /**
     * Whether a record is flagged sensitive and must be encrypted (or dropped).
     *
     * @param array<string, mixed> $record The record to inspect.
     *
     * @return bool True if the record carries a truthy `secure` or `sensitive` marker.
     */
    private function isSensitive(array $record): bool
    {
        return ($record['secure'] ?? false) === true
            || ($record['sensitive'] ?? false) === true;
    }

    /**
     * Forward a sensitive record with its message AND context encrypted together.
     *
     * Fail-closed: with no encryptor configured, or if encryption fails, the record
     * is dropped — never forwarded in plaintext. Trace correlation stays plaintext.
     *
     * @param array<string, mixed> $record The engine record flagged secure().
     */
    private function writeSensitive(array $record): void
    {
        if ($this->encryptor === null) {
            return;
        }

        try {
            $payload = json_encode(
                [
                    'message' => $this->toString($record['message'] ?? null),
                    'context' => $this->toArray($record['context'] ?? null),
                ],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            );

            $ciphertext = $this->encryptor->encrypt($payload);
        } catch (Throwable) {
            return;
        }

        $this->logger->log(
            $this->toLevel($record['level'] ?? null),
            $ciphertext,
            [
                'channel'   => $this->toString($record['channel'] ?? null, 'app'),
                'trace_id'  => $this->toString($record['trace_id'] ?? null),
                'span_id'   => $this->toString($record['span_id'] ?? null),
                'encrypted' => true,
            ],
        );
    }

    /**
     * Forward a log record at its own level, with trace correlation attached.
     *
     * @param array<string, mixed> $record The engine log record.
     */
    private function writeLog(array $record): void
    {
        $context = $this->toArray($record['context'] ?? null);
        $context['channel']  = $this->toString($record['channel'] ?? null, 'app');
        $context['trace_id'] = $this->toString($record['trace_id'] ?? null);
        $context['span_id']  = $this->toString($record['span_id'] ?? null);

        $this->logger->log(
            $this->toLevel($record['level'] ?? null),
            $this->toString($record['message'] ?? null),
            $context,
        );
    }

    /**
     * Forward a finished span as a single correlated log line.
     *
     * @param array<string, mixed> $record The engine span record.
     */
    private function writeSpan(array $record): void
    {
        $status = $this->toString($record['status'] ?? null);
        $level  = strtolower($status) === 'error' ? LogLevel::ERROR : LogLevel::INFO;

        $this->logger->log(
            $level,
            'span ' . $this->toString($record['name'] ?? null, 'span'),
            [
                'channel'        => $this->toString($record['channel'] ?? null, 'app'),
                'trace_id'       => $this->toString($record['trace_id'] ?? null),
                'span_id'        => $this->toString($record['span_id'] ?? null),
                'parent_span_id' => $this->toString($record['parent_span_id'] ?? null),
                'kind'           => $this->toString($record['kind'] ?? null),
                'duration_ms'    => $this->toFloat($record['duration_ms'] ?? null),
                'status'         => $status,
                'status_message' => $this->toString($record['status_message'] ?? null),
                'attributes'     => $this->toArray($record['attributes'] ?? null),
                'events'         => $this->toArray($record['events'] ?? null),
            ],
        );
    }

    /**
     * Map the engine level token to a valid PSR-3 level, defaulting to info.
     *
     * @param mixed $level The engine level token.
     *
     * @return string A valid PSR-3 level string.
     */
    private function toLevel(mixed $level): string
    {
        if (is_string($level) && in_array($level, self::LEVELS, true)) {
            return $level;
        }

        return LogLevel::INFO;
    }

    /**
     * Coerce a mixed value into a string, falling back to a default.
     *
     * @param mixed $value The value to coerce.
     * @param string $default The fallback when the value is not a stringable scalar.
     *
     * @return string The coerced string.
     */
    private function toString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Coerce a mixed value into a float, falling back to 0.0.
     *
     * @param mixed $value The value to coerce.
     *
     * @return float The coerced float.
     */
    private function toFloat(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * Coerce a mixed value into an array, falling back to an empty array.
     *
     * @param mixed $value The value to coerce.
     *
     * @return array<array-key, mixed> The coerced array.
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
