<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit\Analysis;

use IdempotencyLinter\Analysis\Catalog\Catalog;
use IdempotencyLinter\Report\RiskLevel;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function test_loads_the_package_config(): void
    {
        $catalog = Catalog::fromConfig(require __DIR__.'/../../../config/idempotency-linter.php');

        $this->assertNotEmpty($catalog->sinks);
        $this->assertNotEmpty($catalog->guards);
    }

    public function test_builds_typed_sinks_and_guards(): void
    {
        $catalog = Catalog::fromConfig([
            'sinks' => [
                'mail' => [
                    'risk' => 'medium',
                    'message' => 'Envio de e-mail.',
                    'match' => [['type' => 'static_call', 'class' => 'Mail', 'methods' => ['send']]],
                ],
            ],
            'guards' => [
                'unique_job' => [
                    'partial' => true,
                    'match' => [['type' => 'interface', 'class' => 'ShouldBeUnique']],
                ],
                'key' => [
                    'match' => [['type' => 'array_key', 'keys' => ['idempotency_key']]],
                ],
            ],
        ]);

        $this->assertSame('mail', $catalog->sinks[0]->name);
        $this->assertSame(RiskLevel::Medium, $catalog->sinks[0]->risk);
        $this->assertSame('Envio de e-mail.', $catalog->sinks[0]->message);
        $this->assertSame('static_call', $catalog->sinks[0]->rules[0]->type);
        $this->assertSame('Mail', $catalog->sinks[0]->rules[0]->class);
        $this->assertSame(['send'], $catalog->sinks[0]->rules[0]->methods);

        $this->assertTrue($catalog->guards[0]->partial);
        $this->assertFalse($catalog->guards[1]->partial);
        $this->assertSame(['idempotency_key'], $catalog->guards[1]->rules[0]->keys);
    }

    public function test_missing_sections_are_empty(): void
    {
        $catalog = Catalog::fromConfig([]);

        $this->assertSame([], $catalog->sinks);
        $this->assertSame([], $catalog->guards);
    }

    public function test_rejects_invalid_risk(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('banana');

        Catalog::fromConfig(['sinks' => ['x' => ['risk' => 'banana', 'match' => []]]]);
    }

    public function test_rejects_unknown_match_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('magic');

        Catalog::fromConfig(['guards' => ['x' => ['match' => [['type' => 'magic']]]]]);
    }

    public function test_builds_chains(): void
    {
        $catalog = Catalog::fromConfig([
            'chains' => [
                ['class' => '\Mail', 'methods' => ['to', 'cc'], 'returns' => '\PendingMail'],
            ],
        ]);

        $this->assertCount(1, $catalog->chains);
        $this->assertSame('Mail', $catalog->chains[0]->class);
        $this->assertSame(['to', 'cc'], $catalog->chains[0]->methods);
        $this->assertSame('PendingMail', $catalog->chains[0]->returns);
    }

    public function test_package_config_declares_chains(): void
    {
        $catalog = Catalog::fromConfig(require __DIR__.'/../../../config/idempotency-linter.php');

        $this->assertNotEmpty($catalog->chains);
    }

    public function test_rejects_chain_without_returns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('returns');

        Catalog::fromConfig(['chains' => [['class' => 'Mail', 'methods' => ['to']]]]);
    }
}
