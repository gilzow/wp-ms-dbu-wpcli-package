<?php

namespace WP_CLI\MsDbu;

use WP_CLI;
use WP_CLI_Command;

class MsDbuCommand extends WP_CLI_Command {
  protected array $rawRoutes = [];

  protected array $filteredRoutes = [];
  protected string $appName;
  protected string $envVarPrefix = "PLATFORM_";
  protected array $searchColumns = [
    'option_value',
    'post_content',
    'post_excerpt',
    'post_content_filtered',
    'meta_value',
    'domain'
  ];

  /**
   * Updates WordPress multisites in non-production environments on Platform.sh.
   *
   * ## OPTIONS
   *  [--routes=<routes>]
   * : JSON object that describes the routes for the environment. @see PLATFORM_ROUTES https://docs.platform.sh/development/variables/use-variables.html#use-provided-variables
   *
   * [--app-name=<app-name>]
   * : The app name as set in your app configuration.
   *
   * ## EXAMPLES
   *
   *     # Update the database with environment routes
   *     $ wp ms-dbu
   *     Success: All domains have been updated!
   *
   *
   * @param array $args       Indexed array of positional arguments.
   * @param array $assoc_args Associative array of associative arguments.
   */
  public function __invoke( $args, $assoc_args ) {
    //figure out where we get our route info
    $routes = (isset($assoc_args['app-name']) && "" !== $assoc_args['app-name']) ?: $this->getRouteFromEnvVar();
    //save our raw routes data
    $this->setRawRoutes($this->parseRouteJson($routes));
    //save our app name
    $this->setAppName((isset($assoc_args['app-name']) && "" !== $assoc_args['app-name']) ?: $this->getEnvVar('APPLICATION_NAME'));
    //get our filtered route data
    $this->getFilteredRoutes();

    WP_CLI::log("Filtered Routes");
    WP_CLI::log(var_export($this->filteredRoutes,true));

  }



  protected function parseRouteJson(string $routeInfo) {
    $routes = [];
    // json_validate is only available >=8.3.
//    if(!\json_validate($routeInfo)) {
//      WP_CLI::error('Route information does not appear to be valid JSON. Exiting.');
//    }

    try {
      $routes = \json_decode($routeInfo, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      WP_CLI::error(\sprintf('Unable to parse route information. Is it valid JSON? %s', $e->getMessage()));
    }

    return $routes;
  }

  protected function getFilteredRoutes(): void {
    $appName = $this->appName;
    $this->filteredRoutes = \array_filter($this->rawRoutes, static function ($route) use ($appName) {
      return (isset($route['upstream']) && $appName === $route['upstream']);
    });
  }

  protected function getRouteFromEnvVar(): string {
    return \base64_decode($this->getEnvVar('ROUTES'));
  }

  protected function setRawRoutes(array $routes): void {
    $this->rawRoutes = $routes;
  }

  protected function setAppName(string $name): void {
    $this->appName = $name;
  }

  protected function getEnvVar(string $varName) {
    $envVarToGet = $this->envVarPrefix.$varName;
    if(!\getenv($envVarToGet) || "" === \getenv($envVarToGet)) {
      WP_CLI::error(\sprintf("%s is not set or empty. Are you sure you're running on Platform.sh?", $envVarToGet));
    }
    return \getenv($envVarToGet);
  }
}
