<?php

declare(strict_types=1);

namespace BookingApi\Tests\Unit;

use BookingApi\Http\HttpException;
use BookingApi\Http\Request;
use BookingApi\Http\Response;
use BookingApi\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->add('GET', '/bookings/{id}', fn (Request $r, array $p): Response => new Response(200, $p));
        $this->router->add('POST', '/bookings/{id}/cancel', fn (Request $r, array $p): Response => new Response(200, ['cancel' => $p['id']]));
    }

    public function testExtractsParameters(): void
    {
        self::assertSame(['id' => '42'], $this->router->dispatch(new Request('GET', '/bookings/42'))->data);
        self::assertSame(['cancel' => '7'], $this->router->dispatch(new Request('POST', '/bookings/7/cancel'))->data);
    }

    public function testUnknownPathIs404(): void
    {
        $this->assertStatus(404, new Request('GET', '/bookings/abc'));
    }

    public function testWrongMethodIs405(): void
    {
        $this->assertStatus(405, new Request('DELETE', '/bookings/42'));
    }

    private function assertStatus(int $status, Request $request): void
    {
        try {
            $this->router->dispatch($request);
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame($status, $e->status);
        }
    }
}
