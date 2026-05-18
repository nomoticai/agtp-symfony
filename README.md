# AGTP for Symfony

A Symfony bundle that wires AGTP handlers into your Symfony app's
service container. The pattern matches the rest of Symfony: register
your handler as a service, tag it with `agtp.endpoint`, and you're
done.

Pairs with two Composer packages from the [`agtp-php`][agtp-php-repo]
repo:
- [`agtp/agtp-php`][agtp-php] — the language library
- [`agtp/mod-php`][mod-php] — the runtime client (wrapped by the
  `agtp:serve` Symfony Console command)

The reference `agtpd` daemon (Python) ships from the [AGTP spec
repo][spec-repo].

## Requirements

- Symfony 6.4+ or 7+
- PHP 8.1+
- `agtpd` running locally or on the same host

## Install

```bash
composer require agtp/agtp-symfony
```

Then enable the bundle in `config/bundles.php`:

```php
return [
    // ...
    Agtp\Symfony\AgtpBundle::class => ['all' => true],
];
```

(Symfony Flex auto-enables bundles via the `extra.symfony.bundles`
declaration in this package's `composer.json`; on a non-Flex setup,
add the line manually.)

## Writing a handler

### 1. The handler class

```php
namespace App\Agtp;

use Agtp\AgtpEndpoint;
use Agtp\EndpointContext;
use Agtp\EndpointError;
use Agtp\EndpointResponse;
use Doctrine\ORM\EntityManagerInterface;

final class RoomHandlers
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[AgtpEndpoint(
        method: 'BOOK',
        path: '/room',
        errors: ['room_unavailable'],
        requiredScopes: ['booking:write'],
    )]
    public function book(EndpointContext $ctx): EndpointResponse|EndpointError
    {
        $room = $this->em->getRepository(Room::class)->findOneBy([
            'type' => $ctx->input['room_type'] ?? 'double',
        ]);
        if ($room === null) {
            return new EndpointError(
                code: 'room_unavailable',
                message: 'No rooms available.',
                details: ['room_type' => $ctx->input['room_type'] ?? null],
            );
        }
        return new EndpointResponse(body: [
            'reservation_id' => 'res-' . $room->getId() . '-' . $ctx->agentId,
        ]);
    }
}
```

### 2. The service registration

In `config/services.yaml`:

```yaml
services:
  App\Agtp\RoomHandlers:
    arguments:
      $em: '@doctrine.orm.entity_manager'
    tags:
      - { name: agtp.endpoint }
```

Or with Symfony's auto-configuration, tag the class via attribute:

```php
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('agtp.endpoint')]
final class RoomHandlers { /* ... */ }
```

## Running the worker

```bash
bin/console agtp:serve --gateway-socket=/var/run/agtpd/gateway.sock
```

Production via systemd:

```ini
[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/example.com
ExecStart=/usr/bin/php bin/console agtp:serve --gateway-socket=/var/run/agtpd/gateway.sock
Environment=APP_ENV=prod
Restart=on-failure
RestartSec=5s
```

For higher concurrency, run multiple unit copies. `agtpd` accepts
multiple module connections.

## Testing handlers

```php
use Agtp\Testing;

public function testBookSuccess(): void
{
    $em = $this->createMock(EntityManagerInterface::class);
    // ... stub repository etc.
    $handler = new RoomHandlers($em);

    $ctx = Testing::makeContext(input: ['room_type' => 'double']);
    $response = Testing::assertOk($handler->book($ctx));
    $this->assertArrayHasKey('reservation_id', $response->body);
}
```

## What this bundle does not do

- Does not route AGTP traffic through Symfony's HTTP kernel.
- Does not expose handlers to anonymous traffic; authentication
  happens at `agtpd`.
- Does not provide an admin UI.

## Related

- [AGTP spec repo][spec-repo] — drafts, `agtpd` reference daemon,
  cross-language conformance tests
- [Server-modules architecture][arch] — daemon / module / library
  layering
- [`agtp-php`][agtp-php-repo] — handler SDK + `mod_php` runtime
- [`agtp-drupal`][drupal] — Drupal equivalent (Drupal's DI is forked
  from Symfony's, so the patterns are nearly identical)
- [`agtp-laravel`][laravel], [`agtp-wordpress`][wp] — sibling
  framework integrations

[agtp-php]: https://packagist.org/packages/agtp/agtp-php
[mod-php]: https://packagist.org/packages/agtp/mod-php
[agtp-php-repo]: https://github.com/nomoticai/agtp-php
[spec-repo]: https://github.com/nomoticai/agtp
[arch]: https://github.com/nomoticai/agtp/blob/main/docs/architecture/server-modules.md
[drupal]: https://github.com/nomoticai/agtp-drupal
[laravel]: https://github.com/nomoticai/agtp-laravel
[wp]: https://github.com/nomoticai/agtp-wordpress
