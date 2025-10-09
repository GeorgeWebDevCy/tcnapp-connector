<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/TokenAuthenticatorTest.php';
require __DIR__ . '/PasswordLoginServiceTest.php';

$test_classes = array(
    'TokenAuthenticatorTest',
    'PasswordLoginServiceTest',
);

$failures = 0;

foreach ( $test_classes as $test_class ) {
    if ( ! class_exists( $test_class ) ) {
        fwrite( STDERR, "Test class {$test_class} not found.\n" );
        exit( 1 );
    }

    $methods = array_filter(
        get_class_methods( $test_class ),
        static function ( string $method ): bool {
            return 0 === strpos( $method, 'test_' );
        }
    );

    foreach ( $methods as $method ) {
        $test_instance = new $test_class();

        try {
            $test_instance->runTestMethod( $method );
            fwrite( STDOUT, sprintf( "✔ %s::%s\n", $test_class, $method ) );
        } catch ( PHPUnit\Framework\AssertionFailedError $e ) {
            fwrite( STDERR, sprintf( "✘ %s::%s: %s\n", $test_class, $method, $e->getMessage() ) );
            $failures++;
        } catch ( Throwable $e ) {
            fwrite( STDERR, sprintf( "✘ %s::%s: %s\n", $test_class, $method, $e->getMessage() ) );
            $failures++;
        }
    }
}

if ( $failures > 0 ) {
    exit( 1 );
}

echo "All tests passed.\n";
