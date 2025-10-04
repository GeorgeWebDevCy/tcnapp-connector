<?php
namespace TCN\Platform\Support;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Updater {
    /**
     * Register the plugin update checker integration.
     */
    public function register(): void {
        if ( ! class_exists( PucFactory::class ) ) {
            return;
        }

        $repository_url = defined( 'TCN_PLATFORM_UPDATE_REPOSITORY_URL' )
            ? TCN_PLATFORM_UPDATE_REPOSITORY_URL
            : 'https://github.com/GeorgeWebDevCy/tcnapp-connector/';
        $repository_url = \apply_filters( 'tcn_platform_update_repository_url', $repository_url );

        $branch = defined( 'TCN_PLATFORM_UPDATE_REPOSITORY_BRANCH' )
            ? TCN_PLATFORM_UPDATE_REPOSITORY_BRANCH
            : 'main';
        $branch = \apply_filters( 'tcn_platform_update_repository_branch', $branch );

        $update_checker = PucFactory::buildUpdateChecker(
            $repository_url,
            \TCN_PLATFORM_PLUGIN_FILE,
            'tcnapp-connector'
        );

        if ( is_string( $branch ) && '' !== $branch ) {
            $update_checker->setBranch( $branch );
        }

        $vcs_api = $update_checker->getVcsApi();
        if ( is_object( $vcs_api ) && method_exists( $vcs_api, 'enableReleaseAssets' ) ) {
            $vcs_api->enableReleaseAssets();
        }
    }
}
