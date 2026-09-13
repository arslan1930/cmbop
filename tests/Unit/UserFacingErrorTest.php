<?php

namespace Tests\Unit;

use App\Models\DepositRequest;
use App\Support\UserFacingError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class UserFacingErrorTest extends TestCase
{
    public function test_deliberate_domain_messages_are_shown(): void
    {
        $this->assertTrue(UserFacingError::isSafe(new \RuntimeException('Insufficient balance')));
        $this->assertTrue(UserFacingError::isSafe(new \Exception('Insufficient balance to reserve')));
        $this->assertTrue(UserFacingError::isSafe(
            new \RuntimeException('This promotional bonus can only be used for purchases within our marketplace.')
        ));
    }

    public function test_database_errors_are_masked(): void
    {
        $query = new QueryException(
            'mysql',
            'select * from `orders`',
            [],
            new \PDOException('SQLSTATE[42S22]: Unknown column "foo"')
        );

        $this->assertFalse(UserFacingError::isSafe($query));
        $this->assertFalse(UserFacingError::isSafe(new \PDOException('SQLSTATE[HY000] connection lost')));
    }

    public function test_php_engine_errors_are_masked(): void
    {
        $this->assertFalse(UserFacingError::isSafe(new \TypeError('Argument #1 must be of type int, string given')));
        $this->assertFalse(UserFacingError::isSafe(new \Error('Call to a member function pay() on null')));
        $this->assertFalse(UserFacingError::isSafe(new \ErrorException('Undefined array key "total"')));
    }

    public function test_messages_that_look_internal_are_masked(): void
    {
        $this->assertFalse(UserFacingError::isSafe(new \Exception('Undefined variable $wallet')));
        $this->assertFalse(UserFacingError::isSafe(new \Exception('Class "App\\Foo" not found')));
        $this->assertFalse(UserFacingError::isSafe(new \Exception('/var/www/vendor/laravel/framework/src/x.php line 12')));
        $this->assertFalse(UserFacingError::isSafe(new \Exception('cURL error 28: Operation timed out')));
        $this->assertFalse(UserFacingError::isSafe(new \Exception('')));
        $this->assertFalse(UserFacingError::isSafe(new \Exception(str_repeat('a', 250))));
        $this->assertFalse(UserFacingError::isSafe(
            (new ModelNotFoundException)->setModel(DepositRequest::class, [999999])
        ));
        $this->assertFalse(UserFacingError::isSafe(
            new \Exception('No query results for model [App\\Models\\DepositRequest] 999999')
        ));
    }

    public function test_message_returns_fallback_for_internal_errors(): void
    {
        $fallback = 'We could not load your orders. Please try again.';

        $this->assertSame(
            $fallback,
            UserFacingError::message(new \TypeError('boom must be of type int'), $fallback)
        );
    }

    public function test_message_returns_domain_text_for_safe_errors(): void
    {
        $this->assertSame(
            'Insufficient balance',
            UserFacingError::message(new \RuntimeException('Insufficient balance'), 'Generic fallback.')
        );
    }

    public function test_safe_text_masks_internal_fragments(): void
    {
        $this->assertSame(
            'Metrics refresh failed.',
            UserFacingError::safeText('SQLSTATE[42S22]: Unknown column "foo"', 'Metrics refresh failed.')
        );
        $this->assertSame(
            'previous preview kept',
            UserFacingError::safeText('previous preview kept', 'Screenshot capture failed.')
        );
        $this->assertSame(
            'Screenshot capture failed.',
            UserFacingError::safeText('', 'Screenshot capture failed.')
        );
    }
}
