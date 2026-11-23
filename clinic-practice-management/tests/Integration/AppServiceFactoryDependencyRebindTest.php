<?php

declare(strict_types=1);

namespace ClinicCore\Tests\Integration;

use ClinicCore\Application\Auth\OtpService;
use ClinicCore\Application\Booking\BookingService;
use ClinicCore\Bootstrap\App;
use WP_UnitTestCase;

/** Independent regression proof for function-local static App factories after App singleton reset. */
final class AppServiceFactoryDependencyRebindTest extends WP_UnitTestCase
{
    public function testBookingAndOtpFactoriesRebuildWhenTheirDatabaseSingletonIsReset(): void
    {
        $bookingBefore = App::bookingService();
        $otpBefore     = App::otpService();
        self::assertInstanceOf(BookingService::class, $bookingBefore);
        self::assertInstanceOf(OtpService::class, $otpBefore);

        $databaseBefore = App::db();
        $reflection = new \ReflectionClass(App::class);
        foreach ([
            'db', 'op', 'audit', 'jobs', 'rate', 'login_rate_limiter', 'idem',
            'settings_factory', 'installationSettings', 'migrations', 'dispatcher',
            'providers', 'vault', 'sms_service', 'license_gate', 'visit_service',
        ] as $propertyName) {
            $property = $reflection->getProperty($propertyName);
            $property->setAccessible(true);
            $property->setValue(null, null);
        }

        $bookingAfter = App::bookingService();
        $otpAfter     = App::otpService();

        self::assertNotSame($databaseBefore, App::db(), 'the App database singleton was replaced for the reset simulation');
        self::assertNotSame($bookingBefore, $bookingAfter, 'bookingService must rebind its cached graph to reset App singletons');
        self::assertNotSame($otpBefore, $otpAfter, 'otpService must rebind its cached graph to reset App singletons');
    }

    // Pin the static property names on App (PR16-ROUND9): the integration
    // reset loops reflect on these names; a silent rename would void every
    // reset guard without failing anything.
    public function testAppDeclaresSnakeCaseSingletonStaticsAndNotCamelCaseAliases(): void
    {
        $reflection = new \ReflectionClass(App::class);
        foreach (['login_rate_limiter', 'settings_factory', 'sms_service', 'license_gate', 'visit_service'] as $name) {
            self::assertTrue($reflection->hasProperty($name), 'App must declare static $' . $name);
            self::assertTrue($reflection->getProperty($name)->isStatic(), 'App::$' . $name . ' must be static');
        }
        foreach (['loginRateLimiter', 'settingsFactory', 'smsService', 'licenseGate', 'visitService'] as $name) {
            self::assertFalse($reflection->hasProperty($name), 'App must NOT declare a stale camelCase static $' . $name);
        }
        // installationSettings is declared camelCase by design and stays.
        self::assertTrue($reflection->hasProperty('installationSettings'));
    }

    // Pin the backward-compat alias for the companion installer lane (PR16-ROUND10):
    // tools/disposable-demo-installer calls App::locationService().
    public function testLocationServiceBackwardCompatAliasDelegatesToNewAccessor(): void
    {
        self::assertTrue(method_exists(App::class, 'locationService'));
        self::assertTrue(method_exists(App::class, 'location_service'));
        self::assertSame(App::location_service(), App::locationService());
    }

    public function testResetListNamesUsedByIntegrationSuitesAreDeclaredOnApp(): void
    {
        $reflection = new \ReflectionClass(App::class);
        foreach ([
            'db', 'op', 'audit', 'jobs', 'rate', 'login_rate_limiter', 'idem',
            'settings_factory', 'installationSettings', 'migrations', 'dispatcher',
            'providers', 'vault', 'sms_service', 'license_gate', 'visit_service',
        ] as $name) {
            self::assertArrayHasKey($name, $reflection->getStaticProperties(), 'App must declare static $' . $name);
            self::assertTrue($reflection->getProperty($name)->isStatic(), 'App::$' . $name . ' must be static');
        }
    }
}
