<?php

declare(strict_types=1);

namespace Agtp\Symfony\Tests\DependencyInjection;

use Agtp\AgtpEndpoint;
use Agtp\EndpointContext;
use Agtp\EndpointResponse;
use Agtp\HandlerRegistry;
use Agtp\Symfony\DependencyInjection\AgtpHandlerPass;
use Agtp\Symfony\Registry\AgtpHandlerCollector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Verify the compiler pass wires every service tagged ``agtp.endpoint``
 * into the AgtpHandlerCollector at compile time.
 *
 * This test builds a real ContainerBuilder, registers a stub handler
 * with the right tag, runs the pass, and asserts the collector
 * actually picks the handler up after the container compiles.
 */
final class AgtpHandlerPassTest extends TestCase
{
    protected function setUp(): void
    {
        HandlerRegistry::resetDefault();
    }

    protected function tearDown(): void
    {
        HandlerRegistry::resetDefault();
    }

    public function testTaggedServicesAreCollectedAtCompileTime(): void
    {
        $container = new ContainerBuilder();

        // The collector definition the bundle would register.
        $collectorDef = new Definition(AgtpHandlerCollector::class);
        $collectorDef->setPublic(true);
        $collectorDef->setArgument('$taggedHandlers', []);
        $container->setDefinition('agtp.handler_collector', $collectorDef);

        // A fake handler service that the pass should pick up.
        $handlerDef = new Definition(AgtpHandlerPassTestHandler::class);
        $handlerDef->addTag('agtp.endpoint');
        $container->setDefinition('app.handler', $handlerDef);

        // A non-tagged service that the pass should ignore.
        $unrelatedDef = new Definition(AgtpHandlerPassTestHandler::class);
        $container->setDefinition('app.unrelated', $unrelatedDef);

        $container->addCompilerPass(new AgtpHandlerPass());
        $container->compile();

        $collector = $container->get('agtp.handler_collector');
        $this->assertInstanceOf(AgtpHandlerCollector::class, $collector);

        $registered = $collector->collect(HandlerRegistry::default());
        $this->assertCount(1, $registered);
        $this->assertSame('PING', $registered[0]->method);
        $this->assertSame('/test', $registered[0]->path);
    }

    public function testPassIsAnoopWhenCollectorIsAbsent(): void
    {
        $container = new ContainerBuilder();

        $handlerDef = new Definition(AgtpHandlerPassTestHandler::class);
        $handlerDef->addTag('agtp.endpoint');
        $container->setDefinition('app.handler', $handlerDef);

        // No agtp.handler_collector definition. Pass must not throw.
        $container->addCompilerPass(new AgtpHandlerPass());
        $container->compile();

        $this->assertTrue(true, 'compilation completed without exception');
    }

    public function testMultipleTaggedServicesAreAllCollected(): void
    {
        $container = new ContainerBuilder();

        $collectorDef = new Definition(AgtpHandlerCollector::class);
        $collectorDef->setPublic(true);
        $collectorDef->setArgument('$taggedHandlers', []);
        $container->setDefinition('agtp.handler_collector', $collectorDef);

        $container->setDefinition(
            'app.handler.a',
            (new Definition(AgtpHandlerPassTestHandlerA::class))->addTag('agtp.endpoint'),
        );
        $container->setDefinition(
            'app.handler.b',
            (new Definition(AgtpHandlerPassTestHandlerB::class))->addTag('agtp.endpoint'),
        );

        $container->addCompilerPass(new AgtpHandlerPass());
        $container->compile();

        $collector = $container->get('agtp.handler_collector');
        $registered = $collector->collect(HandlerRegistry::default());

        $this->assertCount(2, $registered);
        $methods = array_map(fn($r) => $r->method, $registered);
        sort($methods);
        $this->assertSame(['ONE', 'TWO'], $methods);
    }
}

/**
 * Stub handler used as a test fixture.
 */
final class AgtpHandlerPassTestHandler
{
    #[AgtpEndpoint(method: 'PING', path: '/test')]
    public function ping(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: ['ok' => true]);
    }
}

final class AgtpHandlerPassTestHandlerA
{
    #[AgtpEndpoint(method: 'ONE', path: '/a')]
    public function one(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: []);
    }
}

final class AgtpHandlerPassTestHandlerB
{
    #[AgtpEndpoint(method: 'TWO', path: '/b')]
    public function two(EndpointContext $ctx): EndpointResponse
    {
        return new EndpointResponse(body: []);
    }
}
