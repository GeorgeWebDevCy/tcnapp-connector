<?php
namespace TCN\Platform;

use TCN\Platform\Admin\Assets;
use TCN\Platform\Admin\ApiTesterPage;
use TCN\Platform\Admin\ChecklistPage;
use TCN\Platform\Admin\LogPage;
use TCN\Platform\Admin\SettingsPage;
use TCN\Platform\Auth\JwtAuthEndpoints;
use TCN\Platform\Auth\PasswordLoginService;
use TCN\Platform\Auth\TokenAuthenticator;
use TCN\Platform\Membership\MembershipModule;
use TCN\Platform\Rest\ProfileEndpoints;
use TCN\Platform\Rest\WooCommerceEndpoints;
use TCN\Platform\Support\Modules;
use TCN\Platform\Support\Roles;
use TCN\Platform\Support\ActivityMonitor;
use TCN\Platform\Support\Updater;

/**
 * Central orchestration point for the plugin lifecycle.
 *
 * The Plugin class is intentionally lightweight: it wires together supporting services and
 * defers execution to WordPress hooks so the runtime cost stays minimal on each page load.
 */
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
        // Modules encapsulate feature flags and configuration toggles that may be manipulated via
        // the admin UI. Instantiating the registry up-front allows dependent services to query
        // availability without having to recreate state.
        $this->modules = new Modules();
    }

    public function boot(): void {
        // Ensure localisation files are loaded as soon as WordPress has set up its textdomain
        // infrastructure. Using plugins_loaded keeps the call late enough for translation files to
        // be available while still running before we register any strings.
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        // Roles::ensure_roles() may be required immediately (e.g. when capability checks fire
        // during activation). Running it once here guards against missing roles in cases where
        // init hooks are not yet registered (CLI, cron, etc.).
        Roles::ensure_roles();
        // Re-run the role bootstrap on init to catch upgrades or dynamic role changes after the
        // plugin is already activated.
        add_action( 'init', array( Roles::class, 'ensure_roles' ) );
        // Populate the $services array with all pluggable modules before we iterate and register
        // them.
        $this->register_services();

        foreach ( $this->services as $service ) {
            // Each service exposes a register() method that hooks into WordPress. We defensively
            // check the method exists so the plugin does not fatally error when a service follows a
            // different contract (e.g. legacy classes).
            if ( is_object( $service ) && method_exists( $service, 'register' ) ) {
                $service->register();
            }
        }

        // Broadcast a custom action so third parties (or the test suite) can attach their own
        // services after the core plugin has completed its bootstrap sequence.
        do_action( 'tcn_platform_bootstrapped', $this );
    }

    public function load_textdomain(): void {
        // load_plugin_textdomain expects the relative languages directory. Using plugin_basename
        // ensures compatibility with installations that rename the plugin folder.
        load_plugin_textdomain( 'tcnapp-connector', false, dirname( plugin_basename( TCN_PLATFORM_PLUGIN_FILE ) ) . '/languages' );
    }

    protected function register_services(): void {
        // The TokenAuthenticator is a shared dependency for REST endpoints and the password login
        // flow. We configure its hooks immediately so it can intercept authentication before other
        // filters run.
        $token_authenticator = new TokenAuthenticator();
        $token_authenticator->register_hooks();

        // Membership module coordinates API integrations and needs the module registry plus the
        // shared authenticator instance to issue tokens.
        $this->services[] = new MembershipModule( $this->modules, $token_authenticator );
        // ActivityMonitor tracks background events (e.g. logins) and exposes them to admins.
        $this->services[] = new ActivityMonitor();
        // Updater checks remote endpoints for version updates and applies migrations.
        $this->services[] = new Updater();

        if ( $this->modules->is_enabled( Modules::MODULE_AUTH_LOGIN ) ) {
            // When the login module is enabled we register the services that power credential-less
            // sign-ins and the REST endpoints that issue JWTs.
            $this->services[] = new PasswordLoginService( $token_authenticator );
            $this->services[] = new JwtAuthEndpoints();
        } else {
            // If the module is disabled we still expose the legacy action for backwards
            // compatibility so extensions that expect the class alias do not break.
            PasswordLoginService::register_compatibility_alias();
        }

        if ( function_exists( 'wc_get_customer_id_by_email' ) ) {
            // WooCommerce endpoints are only relevant when WooCommerce is active. Checking for a
            // core function avoids adding a hard dependency to the plugin.
            $this->services[] = new WooCommerceEndpoints();
        }

        // Profile endpoints remain available regardless of WooCommerce because other integrations
        // rely on them to fetch user data.
        $this->services[] = new ProfileEndpoints( $token_authenticator );

        if ( is_admin() ) {
            // Admin-only services register menus, enqueue assets, and expose operational tools. We
            // keep them out of the public runtime to minimise front-end overhead.
            $this->services[] = new SettingsPage( $this->modules );
            $this->services[] = new LogPage();
            $this->services[] = new ApiTesterPage();
            $this->services[] = new ChecklistPage();
            $this->services[] = new Assets();
        }
    }

    public function get_modules(): Modules {
        // Expose the module registry so other parts of the system (or third-party extensions)
        // can interrogate feature flags without needing to instantiate the Plugin class again.
        return $this->modules;
    }
}
