<?php

declare(strict_types=1);

namespace IdempotencyLinter\Tests\Unit\Analysis;

use ArrayAccess;
use IdempotencyLinter\Analysis\Matching\ClassMatcher;
use IdempotencyLinter\Tests\Fixtures\Models\Customer;
use IdempotencyLinter\Tests\Fixtures\Models\Invoice;
use IdempotencyLinter\Tests\Fixtures\Models\VipCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClassMatcherTest extends TestCase
{
    public function test_matches_same_class_ignoring_case_and_leading_backslash(): void
    {
        $this->assertTrue((new ClassMatcher)->matches('\illuminate\database\eloquent\model', Model::class));
    }

    public function test_matches_subclass(): void
    {
        $this->assertTrue((new ClassMatcher)->matches(Invoice::class, Model::class));
    }

    public function test_matches_implemented_interface(): void
    {
        $this->assertTrue((new ClassMatcher)->matches(Model::class, ArrayAccess::class));
    }

    public function test_matches_trait_used_by_class_or_parent(): void
    {
        $matcher = new ClassMatcher;

        $this->assertTrue($matcher->matches(Customer::class, Notifiable::class));
        $this->assertTrue($matcher->matches(VipCustomer::class, Notifiable::class));
    }

    public function test_does_not_match_unrelated_classes(): void
    {
        $this->assertFalse((new ClassMatcher)->matches(Invoice::class, Customer::class));
    }

    public function test_unknown_class_does_not_match_and_does_not_fail(): void
    {
        $matcher = new ClassMatcher;

        $this->assertFalse($matcher->matches('Nao\Existe', Model::class));
        $this->assertFalse($matcher->matches(Invoice::class, 'Nao\Existe'));
    }

    public function test_failing_autoload_does_not_match_and_does_not_fail(): void
    {
        $loader = static function (string $class): void {
            if ($class === 'Explode\Thrower') {
                throw new RuntimeException('autoload quebrado');
            }
        };

        spl_autoload_register($loader);

        try {
            $this->assertFalse((new ClassMatcher)->matches('Explode\Thrower', Model::class));
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}
