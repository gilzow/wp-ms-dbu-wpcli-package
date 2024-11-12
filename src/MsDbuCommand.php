<?php

namespace WP_CLI\MsDbu;

use WP_CLI;
use WP_CLI_Command;

class MsDbuCommand extends WP_CLI_Command {
  protected array $rawRoutes = [];
  protected array $defaultDomainInfo = [];
  protected array $filteredRoutes = [];
  protected array $sites=[];
  protected string $defaultReplaceURLFull;
  protected string $defaultSearchURL;
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
    $this->setDefaultDomainInfo();
    $this->setDefaultReplaceURL();
    $this->setDefaultSearchURL();

//    WP_CLI::log("default domain info:");
//    WP_CLI::log(var_export($this->defaultDomainInfo,true));
//
//    WP_CLI::log(sprintf('default replace url: %s', $this->defaultReplaceURLFull));
//
//    WP_CLI::log(sprintf('default search URL: %s',$this->defaultSearchURL));
    global $wpdb;
    WP_CLI::log(sprintf('Table prefix is: %s', $wpdb->base_prefix));

    $this->getSites();
    WP_CLI::log('Our sites from the db:');
    WP_CLI::log(var_export($this->sites,true));

    $this->orderFilteredRoutesByDomainLength();

//    WP_CLI::log('our filtered routes reordered');
//    WP_CLI::log(var_export($this->filteredRoutes,true));

  }

  protected function getSites(): void {
    $this->sites = array_map(function ($site) {
      $site->url = sprintf('https://%s/',$site->domain);
      return (array) $site;
    },get_sites());
  }
  protected function setDefaultDomainInfo(): void {
    /**
     * we now have (filteredRoutes) a list of NEW domains that are connected to our application as keys, with an array
     * of values that include production_url which is our "from" url, as well as a primary attribute to indicate which
     * one is our default domain. Now we need the "primary" domain (aka default_domain). It *should* be the first item
     * in the array but should we rely on that assumption or should we array_filter so we know we're getting the correct
     * one?
     * @todo there should be one, and one only. should we verify and if not true, throw an error?
     */
    $this->defaultDomainInfo = array_filter($this->filteredRoutes, static function ($route) {
      return (isset($route['primary']) && $route['primary']);
    });

    if(count($this->defaultDomainInfo) !== 1 ) {
      WP_CLI::warning(sprintf('Default domain info does not contain exactly one entry. In contains %d.', count($this->defaultDomainInfo)));
    }
  }

  /**
   * @return void
   * @todo seems like some of these we could use a magic get and just return the correct data?
   */
  protected function setDefaultReplaceURL(): void {
    $this->defaultReplaceURLFull = array_key_first($this->defaultDomainInfo);
  }

  /**
   * @return void
   * @todo seems like some of these we could use a magic get and just return the correct data?
   */
  protected function setDefaultSearchURL(): void {
    $this->defaultSearchURL = $this->defaultDomainInfo[$this->defaultReplaceURLFull]['production_url'];
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

  protected function orderFilteredRoutesByDomainLength(): void {
    uasort($this->filteredRoutes, static function ($a, $b) {
      $lena = substr_count($a['production_url'],'.');
      $lenb = substr_count($b['production_url'], '.' );
      if ($lena === $lenb) {
        return 0;
      }

      return ($lena < $lenb) ? 1 : -11;
    });
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
