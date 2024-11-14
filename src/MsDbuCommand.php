<?php

namespace WP_CLI\MsDbu;

use WP_CLI;
use WP_CLI_Command;
use Search_Replace_Command;

class MsDbuCommand extends WP_CLI_Command {
  protected array $rawRoutes = [];
  protected array $defaultDomainInfo = [];
  protected array $filteredRoutes = [];
  protected array $sites=[];
  protected string $defaultReplaceURLFull;
  protected string $defaultSearchURL;
  protected string $appName;
  protected string $envVarPrefix = "PLATFORM_";

  protected string $regexSearchPttrn='(%s(?!\.%s))';
  protected string $tblPrefix = "";
  protected array $searchColumns = [
    'option_value',
    'post_content',
    'post_excerpt',
    'post_content_filtered',
    'meta_value',
    'domain'
  ];

  protected SearchReplacer $searchReplacer;

  protected string $replacePattern = 'wp search-replace \'%s\' %s %s --include-columns=%s --url=%s --verbose';

  protected array $tables = ['site','blogs'];
  protected array $optionsTables = ['options','posts','postmeta'];

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

    $this->setUp($assoc_args);
    $this->updateDB();

  }

  protected function setUp(?array $data): void {
    //figure out where we get our route info
    $routes = (isset($data['routes']) && "" !== $data['routes']) ?: $this->getRouteFromEnvVar();
    //save our raw routes data
    $this->setRawRoutes($this->parseRouteJson($routes));
    //save our app name
    $this->setAppName((isset($data['app-name']) && "" !== $data['app-name']) ?: $this->getEnvVar('APPLICATION_NAME'));
    //get our filtered route data
    $this->getFilteredRoutes();
    $this->setDefaultDomainInfo();
    $this->setDefaultReplaceURL();
    $this->setDefaultSearchURL();
    $this->getTablePrefix();
    $this->updateTablesWithPrefix();
    $this->getSites();
    $this->orderFilteredRoutesByDomainLength();
  }

  protected function updateDB() {
    foreach ($this->filteredRoutes as $urlReplace=>$routeData) {
      $positional = [];
      $associative = ['verbose'=>true,'dry-run'=>true];

      if (false === $blogID = array_search($routeData['production_url'], array_column($this->sites, 'url','blog_id'), true)) {
        WP_CLI::log(sprintf('I am unable to find a blog id for %s. Skipping.',$routeData['production_url']));
        break;
      }

      $domainSearch = parse_url($routeData['production_url'], PHP_URL_HOST);
      $positional[] = $domainSearch;
      $domainReplace = parse_url($urlReplace, PHP_URL_HOST);
      $positional[] = $domainReplace;

      //$targetTables = array_merge($this->tables,$this->processOptionsTables($blogID));
      $positional = [...$positional, ...$this->tables,...$this->processOptionsTables($blogID)];
      ///$searchTables = implode(' ', $targetTables);
      //$searchColumns = implode(' ', $this->searchColumns);
      $associative['include-columns'] = implode(',', $this->searchColumns);
      $associative['url'] = $routeData['production_url'];
      /**
      * For the primary domain, we want to run it through the whole network, otherwise we end up with a mismatch between
      * wp_blogs and a site's wp_#_options table
       */
      //$network = (isset($routeData['primary']) && $routeData['primary']) ? ' --network' : '';
      if(isset($routeData['primary']) && $routeData['primary']) {
        $associative['network'] = true;
      }

      //$command = sprintf($this->replacePattern, $domainSearch, $domainReplace, $searchTables, $searchColumns, $routeData['production_url']);
      WP_CLI::log("positional array:");
      WP_CLI::log(var_export($positional,true));
      WP_CLI::log("associative array:");
      WP_CLI::log(var_export($associative,true));
      $searcher=new Search_Replace_Command();
      $searcher($positional, $associative);
    }
  }

  /**
   * For a given site, we'll need to update a collection of tables related to the site. Tables are named with the format
   * <prefix><blogid>_<table>
   * Given a prefix of `wp_`, a blog id of 2, and the table `options` the name is wp_2_options.
   * HOWEVER, if the blog id is 1, then the table name is `wp_options`
   * @param int $blogId
   * @return array
   */
  protected function processOptionsTables(int $blogId): array {
    return array_map(function ($table) use ($blogId){
      return $this->tblPrefix.((1 === $blogId) ? '' : $blogId . '_').$table;
    }, $this->optionsTables);
  }

  /**
   * Retrieves the list of known sites from the database that need to be updated/processed
   * @return void
   * @uses get_sites()
   */
  protected function getSites(): void {
    $this->sites = array_map(static function ($site) {
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
   * Retrieves and saves the default replacement URL from the default domain information
   * @return void
   * @todo seems like some of these we could use a magic get and just return the correct data?
   */
  protected function setDefaultReplaceURL(): void {
    $this->defaultReplaceURLFull = array_key_first($this->defaultDomainInfo);
  }

  /**
   * Retrieves and saves the default search URL from the default domain information
   * @return void
   * @todo seems like some of these we could use a magic get and just return the correct data?
   */
  protected function setDefaultSearchURL(): void {
    $this->defaultSearchURL = $this->defaultDomainInfo[$this->defaultReplaceURLFull]['production_url'];
  }

  /**
   * Parses the json routes data
   * @param string $routeInfo JSON string of route information
   * @return array|mixed
   * @throws WP_CLI\ExitException
   */
  protected function parseRouteJson(string $routeInfo) {
    $routes = [];

    try {
      $routes = \json_decode($routeInfo, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      WP_CLI::error(\sprintf('Unable to parse route information. Is it valid JSON? %s', $e->getMessage()));
    }

    return $routes;
  }

  /**
   * Filters out all non-primary (ie redirection) routes that are in the collection
   * @return void
   */
  protected function getFilteredRoutes(): void {
    $appName = $this->appName;
    $this->filteredRoutes = \array_filter($this->rawRoutes, static function ($route) use ($appName) {
      return (isset($route['upstream']) && $appName === $route['upstream']);
    });
  }

  /**
   * Returns the decoded routes data from the environment variable <pass-prefix>_ROUTES
   * @return string
   * @throws WP_CLI\ExitException
   */
  protected function getRouteFromEnvVar(): string {
    return \base64_decode($this->getEnvVar('ROUTES'));
  }

  /**
   * Reorders our route array so that subdomains (or sub-sub domains) of a domain are first. ie least specific to most
   * specific
   *
   * We need the default_domain to be processed LAST otherwise any domains that are sub(-sub)domains of it wont be
   * allowed to update their tables. This assumes that our default_domain is first in the list which it *should* be.
   * @todo do we need to search for default_domain, remove it from where it is, and then append it?
   * @return void
   */
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

  /**
   * Saves the raw, unfiltered route array
   * @param array $routes array of route information
   * @return void
   */
  protected function setRawRoutes(array $routes): void {
    $this->rawRoutes = $routes;
  }

  /**
   * Sets the value of the app's name
   * @param string $name App name as defined in the platform/upsun configuration file
   * @return void
   */
  protected function setAppName(string $name): void {
    $this->appName = $name;
  }

  /**
   * Retrieve an environment variable's value.
   * Note - assumes the variable starts with the value in $this->envVarPrefix
   * @param string $varName
   * @return array|false|string
   * @throws WP_CLI\ExitException
   * @todo should/do we need to have it try the requested env var without the prefix?
   */
  protected function getEnvVar(string $varName) {
    $envVarToGet = $this->envVarPrefix.$varName;
    if(!\getenv($envVarToGet) || "" === \getenv($envVarToGet)) {
      WP_CLI::error(\sprintf("%s is not set or empty. Are you sure you're running on Platform.sh?", $envVarToGet));
    }
    return \getenv($envVarToGet);
  }

  /**
   * Retrieve and stpre the table prefix as defined in wp-config
   * @return void
   */
  protected function getTablePrefix(): void {
    global $wpdb;
    $this->tblPrefix = $wpdb->base_prefix;
  }

  /**
   * Updates our array of tables to include the prefix as defined in wp-config
   * @return void
   */
  protected function updateTablesWithPrefix(): void {
    $this->tables = array_map(function ($table) {
      return $this->tblPrefix.$table;
    }, $this->tables);
  }
}
