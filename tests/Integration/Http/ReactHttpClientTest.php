<?php

declare(strict_types=1);

namespace Zakharov\AutoMediaDownloaderServer\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\HttpResponse;
use Zakharov\AutoMediaDownloaderServer\Infrastructure\Http\ReactHttpClient;

use function React\Promise\all;

final class ReactHttpClientTest extends TestCase
{
    #[TestDox('Не идёт по редиректу и передаёт заголовки')]
    public function testDoesNotFollowRedirectAndSendsHeaders(): void
    {
        $server = new HttpServer(static function (ServerRequestInterface $request): Response {
            if ($request->getUri()->getPath() === '/redirect') {
                return new Response(302, ['Location' => '/target'], '');
            }

            return new Response(200, ['Content-Type' => 'text/plain'], 'cookie=' . $request->getHeaderLine('Cookie'));
        });

        $socket = new SocketServer('127.0.0.1:0');
        $server->listen($socket);
        $base = 'http://' . str_replace('tcp://', '', $socket->getAddress());

        $client = new ReactHttpClient(timeout: 5.0);
        $redirect = null;
        $plain = null;

        $first = $client->get($base . '/redirect')->then(function (HttpResponse $r) use (&$redirect): void {
            $redirect = $r;
        });
        $second = $client->get($base . '/plain', ['Cookie' => 'lf_session=abc'])->then(function (HttpResponse $r) use (&$plain): void {
            $plain = $r;
        });

        // Сокет закрывается, когда осели ОБА промиса - иначе второй запрос
        // может закрыть сервер раньше, чем первый успеет ответить.
        // Сторожевой таймер закрывает его и в случае отказа: без него
        // отклонённый промис оставил бы Loop::run() висеть навсегда.
        $guard = Loop::addTimer(10.0, static fn () => $socket->close());
        all([$first, $second])->finally(static function () use ($socket, $guard): void {
            Loop::cancelTimer($guard);
            $socket->close();
        });

        Loop::run();

        self::assertNotNull($redirect);
        self::assertSame(302, $redirect->status, 'клиент не должен идти по редиректу');
        self::assertSame('/target', $redirect->header('location'));

        self::assertNotNull($plain);
        self::assertSame('cookie=lf_session=abc', $plain->body);
    }
}
