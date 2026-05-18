# AGTP for Symfony

A Symfony bundle that wires AGTP handlers into your Symfony app's
service container. The pattern matches the rest of Symfony: register
your handler as a service, tag it with `agtp.endpoint`, and you're
done.

Pairs with:
- [`agtp-php`](https://github.com/nomoticai/agtp-php) — the language
  library and the `mod_php` runtime client (wrapped by the
  `agtp:serve` Symfony Console command).

## Why AGTP instead of HTTP controllers?

Same reasons that apply to Drupal — see the [agtp-drupal
README](https://github.com/nomoticai/agtp-drupal) for the full pitch.
The short version:

- **One Symfony kernel boot per worker, not per request.** AGTP
  handlers run inside a long-lived `bin/console agtp:serve` worker.
  Kernel boot is paid once. Subsequent requests are dispatch +
  handler logic.
- **Identity, scope, and attribution at the protocol level.** Your
  handler receives `$ctx->agentId` already verified and
  `$ctx->authorityScope` already scope-checked. The daemon emits a
  signed Attribution-Record per invocation.
- **No conflict with your HTTP app.** AGTP runs on 4480 via `agtpd`.
  Your HTTP controllers continue to answer on 80/443 as before.

## Requirements

- Symfony 6.4+ or 7+
- PHP 8.1+
- `agtpd` running locally or on the same host

## Deployment compatibility

| Environment | Long-lived workers? | Status |
|---|---|---|
| Self-hosted (VPS, bare metal, Kubernetes, Docker Compose) | Yes — systemd, Supervisor, k8s `Deployment` | **Supported** |
| Platform.sh | Yes — native worker containers (same pattern as Symfony Messenger workers) | Should work; recipe pending |
| Heroku-style PaaS with worker dynos | Yes — declare in `Procfile` | Should work |
| Serverless / FaaS (Lambda, Cloud Run jobs) | No | Not supported. AGTP needs a persistent process. |

The bundle is **self-hosted-first**. The Symfony Messenger
deployment model translates almost verbatim: anywhere you can run a
`bin/console messenger:consume` worker, you can run `bin/console
agtp:serve`.

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

(Symfony Flex auto-enables the bundle via the `extra.symfony.bundles`
declaration in `composer.json`; on a non-Flex setup, add the line
manually.)

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

Or, with Symfony's autoconfiguration, tag the class via attribute:

```php
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('agtp.endpoint')]
final class RoomHandlers { /* ... */ }
```

## Generate the daemon manifest

After authoring handlers, project the `#[AgtpEndpoint]` attributes
into daemon-side endpoint TOML files:

```bash
# Write one TOML per handler into the agtpd endpoints directory
bin/console agtp:export-manifest --output=/etc/agtpd/endpoints

# Or preview to stdout
bin/console agtp:export-manifest --dry-run
```

The attribute is the source of truth. Re-run the command after every
handler change.

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

- [`agtp-php`](https://github.com/nomoticai/agtp-php) — the underlying
  PHP library and runtime
- [`agtp-drupal`](https://github.com/nomoticai/agtp-drupal) — Drupal
  equivalent (Drupal's DI is forked from Symfony's, so the patterns
  are nearly identical)
