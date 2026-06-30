<?php

declare(strict_types=1);

/**
 * PSR-3 Writer Test
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\PsrBridge\Tests;

use PHPdot\Contracts\Logs\WriterInterface;
use PHPdot\PsrBridge\Psr3Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;

final class Psr3WriterTest extends TestCase
{
    // -----------------------------------------------------------------
    // log records
    // -----------------------------------------------------------------

    #[Test]
    public function forwardsALogRecordAtItsOwnLevel(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write([
            'type'    => 'log',
            'level'   => 'warning',
            'message' => 'disk almost full',
            'context' => ['free_mb' => 12],
        ]);

        self::assertCount(1, $logger->calls);
        self::assertSame(LogLevel::WARNING, $logger->calls[0]['level']);
        self::assertSame('disk almost full', $logger->calls[0]['message']);
        self::assertSame(12, $logger->calls[0]['context']['free_mb']);
    }

    #[Test]
    public function addsTraceCorrelationToTheLogContext(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write([
            'level'    => 'info',
            'message'  => 'hi',
            'channel'  => 'http',
            'trace_id' => 'abc123',
            'span_id'  => 'def456',
            'context'  => ['k' => 'v'],
        ]);

        $context = $logger->calls[0]['context'];
        self::assertSame('http', $context['channel']);
        self::assertSame('abc123', $context['trace_id']);
        self::assertSame('def456', $context['span_id']);
        self::assertSame('v', $context['k']);
    }

    #[Test]
    public function defaultsChannelToAppAndIdsToEmptyWhenMissing(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['level' => 'info', 'message' => 'x']);

        $context = $logger->calls[0]['context'];
        self::assertSame('app', $context['channel']);
        self::assertSame('', $context['trace_id']);
        self::assertSame('', $context['span_id']);
    }

    #[Test]
    #[DataProvider('validLevels')]
    public function passesEachValidPsr3LevelThrough(string $level): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['level' => $level, 'message' => 'm']);

        self::assertSame($level, $logger->calls[0]['level']);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function validLevels(): iterable
    {
        yield 'debug'     => [LogLevel::DEBUG];
        yield 'info'      => [LogLevel::INFO];
        yield 'notice'    => [LogLevel::NOTICE];
        yield 'warning'   => [LogLevel::WARNING];
        yield 'error'     => [LogLevel::ERROR];
        yield 'critical'  => [LogLevel::CRITICAL];
        yield 'alert'     => [LogLevel::ALERT];
        yield 'emergency' => [LogLevel::EMERGENCY];
    }

    #[Test]
    public function unknownLevelFallsBackToInfo(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['level' => 'verbose', 'message' => 'm']);

        self::assertSame(LogLevel::INFO, $logger->calls[0]['level']);
    }

    #[Test]
    public function nonStringLevelFallsBackToInfo(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['level' => 7, 'message' => 'm']);

        self::assertSame(LogLevel::INFO, $logger->calls[0]['level']);
    }

    #[Test]
    public function aRecordWithNoTypeIsTreatedAsALog(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['level' => 'error', 'message' => 'boom']);

        self::assertSame(LogLevel::ERROR, $logger->calls[0]['level']);
        self::assertSame('boom', $logger->calls[0]['message']);
    }

    #[Test]
    public function nonStringMessageBecomesEmptyString(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['level' => 'info', 'message' => ['not', 'a', 'string']]);

        self::assertSame('', $logger->calls[0]['message']);
    }

    // -----------------------------------------------------------------
    // span records
    // -----------------------------------------------------------------

    #[Test]
    public function forwardsAFinishedSpanAsOneInfoLine(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write([
            'type'           => 'span',
            'name'           => 'db.query',
            'kind'           => 'client',
            'channel'        => 'db',
            'trace_id'       => 't1',
            'span_id'        => 's1',
            'parent_span_id' => 'p1',
            'duration_ms'    => 4.2,
            'status'         => 'ok',
            'status_message' => '',
            'attributes'     => ['db.rows' => 5],
            'events'         => [],
        ]);

        self::assertCount(1, $logger->calls);
        self::assertSame(LogLevel::INFO, $logger->calls[0]['level']);
        self::assertSame('span db.query', $logger->calls[0]['message']);

        $context = $logger->calls[0]['context'];
        self::assertSame('db', $context['channel']);
        self::assertSame('t1', $context['trace_id']);
        self::assertSame('s1', $context['span_id']);
        self::assertSame('p1', $context['parent_span_id']);
        self::assertSame('client', $context['kind']);
        self::assertSame(4.2, $context['duration_ms']);
        self::assertSame('ok', $context['status']);
        self::assertSame(['db.rows' => 5], $context['attributes']);
    }

    #[Test]
    public function aFailedSpanIsForwardedAtErrorLevel(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['type' => 'span', 'name' => 'x', 'status' => 'error']);

        self::assertSame(LogLevel::ERROR, $logger->calls[0]['level']);
    }

    #[Test]
    public function spanStatusIsMatchedCaseInsensitively(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['type' => 'span', 'name' => 'x', 'status' => 'ERROR']);

        self::assertSame(LogLevel::ERROR, $logger->calls[0]['level']);
    }

    #[Test]
    public function spanWithoutNameUsesTheSpanDefault(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['type' => 'span']);

        self::assertSame('span span', $logger->calls[0]['message']);
        self::assertSame(LogLevel::INFO, $logger->calls[0]['level']);
    }

    #[Test]
    public function spanCoercesMissingDurationToZeroAndArraysToEmpty(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['type' => 'span', 'name' => 'x']);

        $context = $logger->calls[0]['context'];
        self::assertSame(0.0, $context['duration_ms']);
        self::assertSame([], $context['attributes']);
        self::assertSame([], $context['events']);
    }

    #[Test]
    public function spanCoercesIntDurationToFloat(): void
    {
        $logger = $this->logger();

        (new Psr3Writer($logger))->write(['type' => 'span', 'name' => 'x', 'duration_ms' => 5]);

        self::assertSame(5.0, $logger->calls[0]['context']['duration_ms']);
    }

    // -----------------------------------------------------------------
    // crash safety + no sampling
    // -----------------------------------------------------------------

    #[Test]
    public function aThrowingLoggerNeverBringsDownTheCaller(): void
    {
        $writer = new Psr3Writer(new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger exploded');
            }
        });

        // Neither a log nor a span record may surface the logger's failure.
        $writer->write(['level' => 'info', 'message' => 'm']);
        $writer->write(['type' => 'span', 'name' => 'x']);

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function everyRecordIsForwardedWithNoSampling(): void
    {
        $logger = $this->logger();
        $writer = new Psr3Writer($logger);

        for ($i = 0; $i < 50; ++$i) {
            $writer->write(['level' => 'info', 'message' => "m{$i}"]);
        }

        self::assertCount(50, $logger->calls);
    }

    #[Test]
    public function implementsTheEngineWriterInterface(): void
    {
        self::assertInstanceOf(WriterInterface::class, new Psr3Writer($this->logger()));
    }

    // -----------------------------------------------------------------
    // helper
    // -----------------------------------------------------------------

    /**
     * A PSR-3 logger that records every forwarded call for assertions.
     */
    private function logger(): object
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $calls = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->calls[] = [
                    'level'   => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
