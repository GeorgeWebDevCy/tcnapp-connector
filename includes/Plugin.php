<?php
namespace TCN\Platform;

use TCN\Platform\Admin\Assets;
use TCN\Platform\Admin\ApiTesterPage;
use TCN\Platform\Admin\LogPage;
use TCN\Platform\Admin\SettingsPage;
use TCN\Platform\Auth\PasswordLoginService;
use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Membership\MembershipModule;
use TCN\Platform\Rest\ProfileEndpoints;
use TCN\Platform\Rest\WooCommerceEndpoints;
use TCN\Platform\Support\Modules;
use TCN\Platform\Support\ActivityMonitor;
use TCN\Platform\Support\Updater;

class Plugin {
    /**
     * @var Modules
     */
    protected $modules;

    /**
     * @var array<int, object>
     */
    protected $services = array();

    public function __construct() {
        $this->modules = new Modules();
    }

    public function boot(): void {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        $this->register_services();

        foreach ( $this->services as $service ) {
            if ( is_object( $service ) && method_exists( $service, 'register' ) ) {
                $service->register();
            }
        }

        do_action( 'tcn_platform_bootstrapped', $this );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'tcnapp-connector', false, dirname( plugin_basename( TCN_PLATFORM_PLUGIN_FILE ) ) . '/languages' );
    }

    protected function register_services(): void {
        $token_authenticator = new TokenAuthenticator();

        $this->services[] = new MembershipModule( $this->modules, $token_authenticator );
        $this->services[] = new ActivityMonitor();
        $this->services[] = new Updater();

        if ( $this->modules->is_enabled( Modules::MODULE_AUTH_LOGIN ) ) {
            $this->services[] = new PasswordLoginService( $token_authenticator );
        } else {
            PasswordLoginService::register_compatibility_alias();
        }

        if ( function_exists( 'wc_get_customer_id_by_email' ) ) {
            $this->services[] = new WooCommerceEndpoints();
        }

        $this->services[] = new ProfileEndpoints( $token_authenticator );

        if ( is_admin() ) {
            $this->services[] = new SettingsPage( $this->modules );
            $this->services[] = new LogPage();
            $this->services[] = new ApiTesterPage();
            $this->services[] = new Assets();
        }
    }

    public function get_modules(): Modules {
        return $this->modules;
    }
}
