<?php

declare(strict_types=1);

namespace BookingApi;

use BookingApi\Booking\BookingService;
use BookingApi\Booking\CreateBooking;
use BookingApi\Http\HttpException;
use BookingApi\Http\Request;
use BookingApi\Http\Response;
use BookingApi\Http\Router;
use BookingApi\Idempotency\IdempotencyStore;
use BookingApi\Payment\PaymentWebhookHandler;
use BookingApi\Payment\SignatureVerifier;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class App
{
    private readonly Router $router;
    private readonly BookingService $bookings;
    private readonly IdempotencyStore $idempotency;
    private readonly PaymentWebhookHandler $payments;
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock injectable for tests
     */
    public function __construct(
        \PDO $pdo,
        private readonly SignatureVerifier $signatures,
        ?Closure $clock = null,
    ) {
        $this->bookings = new BookingService($pdo);
        $this->idempotency = new IdempotencyStore($pdo);
        $this->payments = new PaymentWebhookHandler($pdo);
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->router = $this->routes();
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (HttpException $e) {
            return $e->toResponse();
        } catch (Throwable $e) {
            error_log((string) $e);

            return new Response(500, ['error' => 'Internal server error.']);
        }
    }

    private function routes(): Router
    {
        $router = new Router();

        $router->add('POST', '/bookings', function (Request $request): Response {
            return $this->idempotency->run(
                $request->header('Idempotency-Key'),
                $request->body,
                fn (): Response => new Response(
                    201,
                    $this->bookings->create(CreateBooking::fromArray($request->json(), ($this->clock)())),
                ),
            );
        });

        $router->add('GET', '/bookings/{id}', fn (Request $request, array $params): Response
            => new Response(200, $this->bookings->get((int) $params['id'])));

        $router->add('POST', '/bookings/{id}/cancel', fn (Request $request, array $params): Response
            => new Response(200, $this->bookings->cancel((int) $params['id'])));

        $router->add('POST', '/webhooks/payments', function (Request $request): Response {
            $this->signatures->verify(
                $request->header('X-Timestamp'),
                $request->header('X-Signature'),
                $request->body,
                ($this->clock)()->getTimestamp(),
            );

            return new Response(200, $this->payments->handle($request->json()));
        });

        return $router;
    }
}
