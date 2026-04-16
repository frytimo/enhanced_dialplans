<?php

declare(strict_types=1);

if (!defined('FILTER_SANITIZE_DOMAIN')) {
	define('FILTER_SANITIZE_DOMAIN', -1);
}

if (!class_exists('url')) {

/**
 * Description of url
 *
 * @author Tim Fry <tim@fusionpbx.com>
 */

/**
 * Lightweight URL helper.
 * - Mutable; chainable setters return $this.
 * - Query helpers for getting/setting/removing params.
 * - Safe(ish) rebuild using http_build_query (RFC3986).
 *
 * Notes:
 * - Expects already-encoded paths; does not auto-encode segments.
 * - Keeps unknown components as-is (e.g., custom schemes).
 * - Validation is minimal; use filter_var(..., FILTER_VALIDATE_URL) if needed.
 */
class url {

	const SORT_NORMAL        = 'natural';
	const SORT_NATURAL       = 'natural';
	const SORT_ASC           = 'asc';
	const SORT_DSC           = 'dsc';
	const BUILD_FORCE_SCHEME = 1;
	const BUILD_FORCE_HOST   = 2;
	const BUILD_FORCE_PATH   = 4;
	const FILTERED           = 0;
	const UNSAFE             = 1;
	/** @deprecated Use FILTERED instead */
	const SAFE               = self::FILTERED;

	// Source identifiers stored as metadata inside $request_params entries
	private const SOURCE_POST  = 'post';
	private const SOURCE_INPUT = 'input';
	private const SOURCE_REQUEST = 'request';

	private $parts;
	private $scheme;
	private $host;
	private $port;
	private $path;

	// URL query parameters — used exclusively for URL building and link generation.
	// Never write POST/body data here; doing so would leak form values into generated links.
	private $params;

	// Merged inbound request store (POST form fields and php://input body).
	// Each entry: [ self::FILTERED => sanitized, self::UNSAFE => raw, 'source' => SOURCE_POST|SOURCE_INPUT ]
	// POST wins over php://input when the same key appears in both (first-value-wins rule).
	// GET query-string values live in $params, not here; get() checks $params first.
	private array $request_params = [];

	private $fragment;
	private $original_url;
	private $username;
	private $password;
	private array $filter_chain = [];

	// Paging properties (active only when settings is provided to the constructor)
	private $settings      = null;
	private $page          = 0;
	private $rows_per_page = 50;
	private $total_rows    = 0;

	public function __construct(?string $url = null, ?array $filters = null, ?settings $settings = null) {
		// initialize object properties
		$this->scheme       = '';
		$this->host         = '';
		$this->port         = '';
		$this->username     = '';
		$this->password     = '';
		$this->path         = '';
		$this->fragment     = '';
		$this->params         = [];
		$this->request_params = [];
		$url                  = $url ?? '';

		// Register any initial filters provided at construction time
		if ($filters !== null) {
			foreach ($filters as $filter) {
				$this->add_query_filter($filter);
			}
		}

		$parsed = parse_url(urldecode($url));

		// must be valid
		if ($parsed === false) {
			throw new InvalidArgumentException("Invalid URL: {$url}");
		}

		// we only support http and https
		if (isset($parsed['scheme']) && !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
			throw new InvalidArgumentException("Unsupported scheme");
		}

		// set the schema
		$this->set_scheme($parsed['scheme'] ?? '');

		// set the host
		$this->set_host($parsed['host'] ?? '');

		// set the port
		$this->set_port($parsed['port'] ?? '');

		// set the path
		$this->set_path($parsed['path'] ?? '');

		// sanitize the query parameters
		$this->set_query($parsed['query'] ?? '');

		// set the fragment or ancore
		$this->set_fragment($parsed['fragment'] ?? '');

		// save the original URL provided
		$this->original_url = $url;

		// Initialize paging state when settings are provided
		if ($settings !== null) {
			$this->settings      = $settings;
			$this->rows_per_page = (int) $settings->get('domain', 'paging', 50);
			// URL param is 1-based (page 1 = first page); convert to 0-based internal index
			$this->page          = max(0, (int) $this->get('page', 1) - 1);
			$this->set_page($this->page);
		}
	}

	/**
	 * Creates a URL object using a URL string
	 * @param string $url
	 * @return self
	 */
	public static function from_string(string $url): static {
		return new self($url);
	}

	/**
	 * Creates a URL object using an associative array of URL parts
	 * @param array $parts
	 * @return self
	 */
	public static function from_parts(array $parts): static {
		$u        = new self();
		// more validation needed here
		$u->parts = $parts;

		return $u;
	}

	/**
	 * Creates a URL object fully populated from the current HTTP request.
	 * Reads $_SERVER['REQUEST_URI'] for GET parameters, loads $_POST for
	 * form values, and also reads php://input for JSON or form-encoded bodies
	 * (e.g. REST API calls that bypass the traditional POST superglobal).
	 *
	 * Pass $settings to enable paging support: rows_per_page is read from the
	 * domain settings and the 'page' query parameter is converted to the
	 * correct 0-based internal page index so offset() returns the right value.
	 *
	 * @param settings|null $settings Optional settings object for paging initialization.
	 * @return static
	 */
	public static function from_request(?settings $settings = null): static {
		$url = new static($_SERVER['REQUEST_URI'] ?? '', null, $settings);
		$url->load_post($_POST);
		$url->load_input();
		$url->load_request($_REQUEST);

		return $url;
	}

	/**
	 * Returns the URL used to create this object
	 * @return string|null
	 */
	public function get_original_url(): ?string {
		return $this->original_url;
	}

	/**
	 * Scheme of the link
	 * @return string
	 */
	public function get_scheme(): string {
		return $this->scheme;
	}

	/**
	 * User of the link
	 * @return string
	 */
	public function get_username(): string {
		return $this->username;
	}

	/**
	 * Password of the link
	 * @return string
	 */
	public function get_password(): string {
		return $this->password;
	}

	/**
	 * Host or domain of the link
	 * @return string
	 */
	public function get_host(): string {
		return $this->host;
	}

	/**
	 * Alias of get_host
	 *
	 * @return string name of the host in the url
	 * @see url::get_host()
	 */
	public function get_domain_name(): string {
		return $this->get_host();
	}

	/**
	 * Port in the link
	 * @return int
	 */
	public function get_port(): string {
		return $this->port;
	}

	/**
	 * Path in the link
	 * @return string
	 */
	public function get_path(): string {
		return $this->path;
	}

	/**
	 * Query in the link
	 *
	 * @param int $unsafe Whether to return the unsafe (original) query parameters or the sanitized ones. Default is self::FILTERED (sanitized).
	 *
	 * @return string
	 */
	public function get_query(int $unsafe = self::FILTERED): string {
		return implode('&', array_map(function ($param, $key) use ($unsafe) {
			$value = is_array($param) ? ($param[$unsafe] ?? $param[self::FILTERED] ?? '') : $param;
			return "$key=" . urlencode((string) $value);
		}, $this->params, array_keys($this->params)));
	}

	public function get_filter_query_modifier(): array {
		return $this->filter_chain;
	}

	/**
	 * Registers a callable in the query filter chain.
	 *
	 * Each filter receives (string $key, mixed $value, callable $next): mixed.
	 * Call $next($key, $value) to pass control to the next filter.
	 * Return null to drop the parameter and stop the chain.
	 *
	 * @param callable $filter fn(string $key, mixed $value, callable $next): mixed
	 * @return static
	 */
	public function add_query_filter(callable $filter): static {
		$this->filter_chain[] = $filter;

		return $this;
	}

	/**
	 * Fragment or ancore in the link
	 * @return string
	 */
	public function get_fragment(): string {
		return $this->fragment;
	}

	/**
	 * Remove a query param
	 * @param string $key Parameter key to remove from the URL query string
	 * @return static Cloned URL object with the specified query parameter removed
	 */
	public function delete(string $key): static {
		$url = clone $this;
		// Remove the query parameter
		return $url->unset_query_param($key);
	}

	public function to_location_header(): string {
		return 'Location: ' . $this->build_absolute();
	}

	public function to_location_header_relative(): string {
		return 'Location: ' . $this->build_relative();
	}

	/**
	 * Sets the scheme part used for the URL link
	 * @param string $scheme
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function set_scheme(string $scheme = ''): static {
		if (strlen($scheme)) {
			$scheme = strtolower($scheme);
			if (!in_array($scheme, ['http', 'https'], true)) {
				throw new InvalidArgumentException("Unsupported scheme");
			}
		}
		$this->scheme = $scheme;

		return $this;
	}

	/**
	 * Sets the user part of the URL
	 * @param string $username
	 * @return self
	 */
	public function set_username(string $username): static {
		$this->username = $username;

		return $this;
	}

	/**
	 * Sets the password part of the URL
	 * @param string $password
	 * @return self
	 */
	public function set_password(string $password): static {
		$this->password = $password;

		return $this;
	}

	/**
	 * Sets the host part of the URL sanitizing the host name before it is stored. If the host part is empty, the host will be removed from the URL.
	 * @param string $host
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function set_host(string $host = ''): static {
		if (strlen($host)) {
			// Use PHP features from 8.3 or higher when available to filter the domain
			if (FILTER_SANITIZE_DOMAIN !== -1) {
				// PHP 8.3 or higher
				$host = filter_var($host, FILTER_SANITIZE_DOMAIN);
			} else {
				// PHP < 8.3
				$host = self::sanitize_host($host);
			}
			if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
				throw new InvalidArgumentException("Invalid host");
			}
		}
		$this->host = $host;

		return $this;
	}

	/**
	 * Helper function to sanitize a domain name. This function is not used when using PHP 8.3 or higher.
	 * @param string $domain
	 * @return string
	 */
	public static function sanitize_host(string $domain): string {
		return preg_replace('/[^a-z0-9.-]/i', '', strtolower($domain));
	}

	/**
	 * Sets the port part of the URL. When the URL is using the scheme of HTTPS or HTTP and the port matches, it will be omitted. If the port is set to zero the port is removed from the URL.
	 * @param int|string $port
	 * @return self
	 * @throws InvalidArgumentException
	 */
	public function set_port($port = ''): static {
		if (strlen("$port")) {
			$port = (int) $port;
			if ($port < 1 || $port > 65535) {
				throw new InvalidArgumentException("Invalid port");
			}
			if ($port == 443 && $this->get_scheme() == 'https' || $port == 80 && $this->get_scheme() == 'http') {
				// Port setting is already implicitly set
				$port = '';
			}
		}
		$this->port = "$port";

		return $this;
	}

	/**
	 * Sets the path part of the URL sanitizing before it is stored using the filter_var function.
	 * @param string $path
	 * @return self
	 * @see filter_var()
	 */
	public function set_path(string $path = ''): static {
		// Strip out suspicious characters but keep slashes
		$this->path = filter_var($path, FILTER_SANITIZE_URL);

		return $this;
	}

	/**
	 * Sets the query part of the URL using a string. When an empty string is provided it will unset all parameters.
	 *
	 * @param string $query Full parameter string without the scheme or domain or path parts
	 *
	 * @return self
	 *
	 * @see self::set_query_param()
	 */
	public function set_query(string $query = ''): static {
		if (strlen($query)) {
			$pos = strpos($query, '#');
			if ($pos > 0) {
				$parts = explode('#', $query, 2);
				if (count($parts) > 1) {
					$this->set_fragment($parts[1]);
				}
				if (count($parts) > 0) {
					$query = $parts[0];
				} else {
					$query = '';
				}
			}
			$params = [];
			$query  = parse_str($query, $params);
			foreach ($params as $key => $value) {
				$this->set_query_param($key, $value);
			}
		} else {
			$this->remove_parameters();
		}

		return $this;
	}

	/**
	 * Sets the fragment or ancore of the URL sanitizing using filter_var before it is stored
	 * @param string $fragment
	 * @return self
	 * @see filter_var()
	 */
	public function set_fragment(string $fragment = ''): static {
		if (strlen($fragment)) {
			$fragment = filter_var($fragment, FILTER_SANITIZE_URL);
		}
		$this->fragment = $fragment;

		return $this;
	}

	/**
	 * Returns an associative array of current queries in the parts
	 * @return array
	 */
	public function get_query_array(): array {
		// return the array
		return $this->params;
	}

	/**
	 * Returns true when the URL currently has one or more query parameters.
	 * @return bool
	 */
	public function has_parameters(): bool {
		return !empty($this->params);
	}

	/**
	 * Alias of set_query_param
	 *
	 * @param string $key Key is converted to lowercase
	 * @param mixed $value
	 *
	 * @return this
	 *
	 * @see url::set_query_param()
	 */
	public function set(string $key, mixed $value): static {
		return $this->set_query_param($key, $value);
	}

	/**
	 * Sets a query parameter sanitizing the value before it is added to the query part
	 * @param string $key Key is converted to lowercase
	 * @param mixed $value
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException
	 */
	public function set_query_param(string $key, mixed $value): static {
		$key = strtolower($key);
		if (!strlen($key)) {
			throw new \InvalidArgumentException("Key must not be empty", 500);
		}

		// Store the unsafe param for reference even if the value is invalid for the filtered parameters
		$this->params[$key][self::UNSAFE] = $value;

		$filtered = $this->filter_query_modifier($key, $value);

		// Only set the filtered param if it is valid after the filter
		if ($filtered !== null) {
			$this->params[$key][self::FILTERED] = $filtered;
		}

		// Allow chaining
		return $this;
	}

	/**
	 * Default sanitization applied before any chain filters.
	 * Subclasses may override this to change built-in validation rules.
	 *
	 * @return mixed Sanitized value, or null to drop the parameter.
	 */
	protected function default_query_filter(string $key, mixed $value): mixed {
		$filtered = filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
		// Remove keys that have invalid values but keep them in unsafe params for reference
		if ($key === 'sort' && !in_array($filtered, [self::SORT_ASC, self::SORT_DSC, self::SORT_NATURAL])) {
			return null;
		}
		if ($key === 'page' && !is_numeric($filtered)) {
			return null;
		}

		return $filtered;
	}

	/**
	 * Applies the default filter and then the registered filter chain to a query parameter.
	 * The default filter is always applied first, and if it returns null, the parameter is dropped immediately. If the default filter returns a non-null value, it is then passed through the registered filter chain in order. Each filter in the chain can modify the value or return null to drop the parameter. The final result after all filters have been applied is returned.
	 *
	 * @param string $key   The key of the query parameter being filtered.
	 * @param mixed  $value The value of the query parameter being filtered.
	 *
	 * @return mixed Final filtered value, or null if dropped by any filter.
	 */
	protected function filter_query_modifier(string $key, mixed $value): mixed {
		// The default filter is always the head of the chain
		$filtered = $this->default_query_filter($key, $value);
		if ($filtered === null || empty($this->filter_chain)) {
			return $filtered;
		}

		// Build the middleware pipeline from last to first, wrapping around a terminus
		$pipeline = fn(string $k, mixed $v) => $v;
		foreach (array_reverse($this->filter_chain) as $callable_filter) {
			$pipeline = fn(string $k, mixed $v) => $callable_filter($k, $v, $pipeline);
		}

		return $pipeline($key, $filtered);
	}

	/**
	 * Removes a query parameter using the key
	 * @param string $key string of the key value
	 * @return self
	 */
	public function unset_query_param(string $key): static {
		unset($this->params[$key]);

		return $this;
	}

	/**
	 * Returns the path segments that were set
	 * @return array
	 */
	public function get_path_segments(): array {
		$segments = [];
		$path     = $this->get_path();
		if (!empty($path)) {
			$segments = array_values(array_filter(explode('/', $path), function ($s) {
				return $s !== '';
			}));
		}

		return $segments;
	}

	/**
	 * Sets the path segments using the strval function
	 * @param array $segments
	 * @return self
	 */
	public function set_path_segments(array $segments): static {
		// Assumes segments are already encoded how you want them
		$this->path = '/' . implode('/', array_map('strval', $segments));

		return $this;
	}

	/**
	 * Appends a path to the current path
	 * @param string $segment
	 * @return self
	 */
	public function append_path(string $segment): static {
		$path       = rtrim((string) ($this->path ?? ''), '/');
		$segment    = ltrim($segment, '/');
		$this->path = ($path === '' ? '' : $path . '/') . $segment;
		if (!str_starts_with($this->path, '/')) {
			$this->path = '/' . $this->path;
		}

		return $this;
	}

	public function to_array(): array {
		$parts = [];

		$scheme = $this->get_scheme();
		if (strlen($scheme)) {
			$parts['scheme'] = $scheme;
		}

		$host = $this->get_host();
		$user = $this->get_username();
		$port = $this->get_port();
		if (strlen($host) || strlen($user) || strlen("$port")) {
			if (strlen($user)) {
				$parts['username'] = $user;
				// password cannot be present without a user
				$pass              = $this->get_password();
				if (strlen($pass)) {
					$parts['password'] = $pass;
				}
			}

			if (strlen($host)) {
				$parts['host'] = $host;
			}

			if (strlen("$port")) {
				$parts['port'] = $port;
			}
		}

		$path = $this->get_path();
		if (strlen($path)) {
			$parts['path'] = $path;
		}

		$query = $this->get_query();
		if (strlen($query)) {
			$parts['query'] = $query;
		}

		$fragment = $this->get_fragment();
		if (strlen($fragment)) {
			$parts['fragment'] = $fragment;
		}

		return $parts;
	}

	/**
	 * Builds a URL from the parts in to a string
	 * @return string URL with all available parts
	 */
	public function build(int $flags = 0): string {
		$string_buffer = '';

		$scheme = $this->get_scheme();
		if (!strlen($scheme) && $flags & self::BUILD_FORCE_SCHEME) {
			$scheme = $_REQUEST['REQUEST_SCHEME'] ?? 'https';
		}
		$host = $this->get_host();
		if (!strlen($host) && $flags & self::BUILD_FORCE_HOST) {
			$host = $_SERVER['SERVER_NAME'];
		}
		$user = $this->get_username();
		$port = $this->get_port();
		$pass = $this->get_password();
		$path = $this->get_path();
		if (!strlen($path) && $flags & self::BUILD_FORCE_PATH) {
			$path = '/';
		}
		$query    = $this->get_query();
		$fragment = $this->get_fragment();

		if (strlen($scheme)) {
			$string_buffer .= $scheme . ':';
		}

		if (strlen($host) || strlen($user) || strlen("$port")) {
			$string_buffer .= '//';

			if (strlen($user)) {
				$string_buffer .= $user;
				// password cannot be present without a user
				if (strlen($pass)) {
					$string_buffer .= ':' . $pass;
				}
				$string_buffer .= '@';
			}

			if (strlen($host)) {
				$string_buffer .= $host;
			}

			if (strlen("$port") && !($scheme == 'https' && "$port" == "443") && !($scheme == 'http' && "$port" == "80")) {
				$string_buffer .= ':' . $port;
			}
		}

		if (strlen($path)) {
			// RFC 3986: when an authority is present, path must begin with '/'
			if ((strlen($host) || strlen($user) || strlen("$port")) && $path[0] !== '/') {
				$path = '/' . $path;
			}
			$string_buffer .= $path;
		}

		if (strlen($query)) {
			$string_buffer .= '?' . $query;
		}

		if (strlen($fragment)) {
			$string_buffer .= '#' . $fragment;
		}

		return $string_buffer;
	}

	public function build_absolute(): string {
		return $this->build(self::BUILD_FORCE_SCHEME ^ self::BUILD_FORCE_HOST ^ self::BUILD_FORCE_PATH);
	}

	public function build_relative(): string {
		$url = clone $this;
		$url->set_scheme()->set_host()->set_port();

		return $url->build();
	}

	/**
	 * Returns a link that is built
	 * @return string
	 */
	public function __toString(): string {
		try {
			return $this->build();
		} catch (Throwable) {
			return '';
		}
	}

	/**
	 * Sets the order_by query parameter
	 * @param string $order_by
	 * @return self
	 */
	public function set_order_by(string $order_by = ''): static {
		// Create a clone
		$url = clone $this;
		if (strlen($order_by) > 0) {
			$url->unset_query_param('order_by');
		} else {
			// set the order_by in the new object
			$url->set_query_param('order_by', $order_by);
		}

		return $url;
	}

	/**
	 * Get order_by query value in URL parts mode.
	 *
	 * @return string
	 */
	public function get_order_by(): string {
		return $this->get_query_param('order_by', '');
	}

	/**
	 * Sets the sort query parameter
	 *
	 * @param string $sort
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException
	 */
	public function set_sort(string $sort = self::SORT_NATURAL): static {
		// Create a clone
		$url = clone $this;
		if ($sort === self::SORT_NATURAL) {
			// natural sorting means no sort
			$url->unset_query_param('sort');
		} else {
			// set the sort param in the new object
			$url->set_query_param('sort', $sort);
		}

		return $url;
	}

	/**
	 * Get sort query value in URL parts mode.
	 *
	 * @return string
	 */
	public function get_sort(): string {
		return (string) $this->get_query_param('sort', self::SORT_NATURAL);
	}

	/**
	 * Return clone with ascending sort in URL parts mode.
	 *
	 * @return self
	 */
	public function sort_asc(): static {
		return $this->set_sort(self::SORT_ASC);
	}

	/**
	 * Return clone with descending sort in URL parts mode.
	 *
	 * @return self
	 */
	public function sort_desc(): static {
		return $this->set_sort(self::SORT_DSC);
	}

	/**
	 * Return clone with natural sorting in URL parts mode.
	 *
	 * @return self
	 */
	public function remove_parameters(): static {
		$this->params   = [];
		$this->fragment = '';

		return $this;
	}

	/**
	 * Replaces the last segment (filename) of the current path with the given resource.
	 *
	 * For example, if the current path is '/app/dialplans/dialplan_edit.php',
	 * calling set_resource('dialplans.php') yields '/app/dialplans/dialplans.php'.
	 *
	 * @param string $resource The new filename or resource to use as the last path segment.
	 * @return static
	 */
	public function set_resource(string $resource): static {
		$path = $this->get_path();
		$dir = substr($path, 0, (int) strrpos($path, '/'));
		$resource = ltrim($resource, '/');
		$this->set_path($dir . '/' . $resource);

		return $this;
	}

	public static function redirect(string $url, int $status_code = 302): void {
		// Check for relative URL to create absolute location
		if (!(str_starts_with($url, 'http://') || str_starts_with($url, 'https://'))) {
			$location = (url::from_request())->set_resource($url)->build_absolute();
		} else {
			$location = url::from_string($url)->build_absolute();
		}
		header("Location: $location", true, $status_code);
		exit();
	}

	// -------------------------------------------------------------------------
	// Inbound request data  (POST form values, php://input bodies, and $_REQUEST fallback)
	//
	// These are kept strictly separate from $params (the URL query store) so
	// POST/body data never leaks into generated links.
	//
	// Priority rule: POST wins over php://input when the same key appears in
	// both.  $_REQUEST is loaded as a fallback source for keys that were not
	// already provided by POST/php://input. URL query params are not stored here; get() checks $params first
	// before falling through to $request_params.
	// -------------------------------------------------------------------------

	/**
	 * Internal helper: sanitizes key/value pairs and merges them into $request_params.
	 *
	 * Each entry uses the same two-slot layout as $params plus a source tag:
	 *   self::FILTERED (0) — value after filter_query_modifier()
	 *   self::UNSAFE   (1) — original, unsanitized value
	 *   'source'       — self::SOURCE_POST or self::SOURCE_INPUT
	 *
	 * Array values have each scalar element sanitized individually; the raw
	 * array is always preserved in the UNSAFE slot.
	 *
	 * @param array  $data      Input key/value pairs.
	 * @param string $source    self::SOURCE_POST, self::SOURCE_INPUT, or self::SOURCE_REQUEST.
	 * @param bool   $overwrite When false, existing keys are left untouched
	 *                          so POST values are not clobbered by later input.
	 */
	private function import_request_params(array $data, string $source, bool $overwrite = false): void {
		foreach ($data as $key => $value) {
			$key = strtolower((string) $key);
			if (!strlen($key)) {
				continue;
			}
			// First-value-wins when $overwrite is false (POST has already been loaded)
			if (!$overwrite && isset($this->request_params[$key])) {
				continue;
			}
			// UNSAFE slot: always store the original value
			$this->request_params[$key][self::UNSAFE] = $value;
			$this->request_params[$key]['source']     = $source;

			if (is_array($value)) {
				// FILTERED slot: sanitize each scalar element individually
				$this->request_params[$key][self::FILTERED] = array_map(
					function ($item) use ($key) {
						return is_scalar($item)
							? $this->filter_query_modifier($key, $item)
							: $item;
					},
					$value
				);
			} else {
				$filtered = $this->filter_query_modifier($key, $value);
				if ($filtered !== null) {
					// FILTERED slot: sanitized scalar
					$this->request_params[$key][self::FILTERED] = $filtered;
				}
			}
		}
	}

	/**
	 * Loads POST form data into the object.
	 *
	 * @param array $post Typically $_POST, but any associative array is accepted.
	 * @return static
	 */
	public function load_post(array $post): static {
		// POST is authoritative; overwrite any php://input values already loaded
		$this->import_request_params($post, self::SOURCE_POST, true);

		return $this;
	}

	/**
	 * Returns a sanitized POST value by key.
	 *
	 * Only returns a value when the key was loaded via load_post().
	 * Use get() for a source-agnostic read.
	 *
	 * @param string $key     POST parameter name (case-insensitive).
	 * @param mixed  $default Returned when the key is absent or came from a different source.
	 * @param bool   $unsafe  When true, returns the original unsanitized value.
	 * @return mixed
	 */
	public function post(string $key, mixed $default = null, bool $unsafe = false): mixed {
		$key  = strtolower($key);
		$slot = $unsafe ? self::UNSAFE : self::FILTERED;
		if (isset($this->request_params[$key]) && $this->request_params[$key]['source'] === self::SOURCE_POST) {
			return $this->request_params[$key][$slot] ?? $default;
		}

		return $default;
	}

	/**
	 * Unified parameter accessor.
	 *
	 * Searches URL query parameters first (persistent page state), then the
	 * merged inbound request store (POST form data, php://input body), returning
	 * the first match found.  Callers that need to know the source should use
	 * get_query_param(), post(), or input() explicitly.
	 *
	 * @param string $key     Parameter name (case-insensitive).
	 * @param mixed  $default Returned when not found in any store.
	 * @param bool   $unsafe  When true, returns the original unsanitized value.
	 * @return mixed
	 */
	public function get(string $key, mixed $default = null, bool $unsafe = false): mixed {
		$lower = strtolower($key);
		$slot  = $unsafe ? self::UNSAFE : self::FILTERED;

		// URL query params take priority — they carry persistent page state
		if (isset($this->params[$lower][$slot])) {
			return $this->params[$lower][$slot];
		}

		// Merged request store (POST form data and php://input body)
		if (isset($this->request_params[$lower][$slot])) {
			return $this->request_params[$lower][$slot];
		}

		return $default;
	}

	/**
	 * Returns true when the key exists in either the URL query parameters
	 * or the merged inbound request store.
	 *
	 * @param string $key Parameter name (case-insensitive).
	 * @return bool
	 */
	public function has(string $key): bool {
		$key = strtolower($key);

		return $this->has_query_param($key) || isset($this->request_params[$key]);
	}

	/**
	 * @deprecated Use post() instead.
	 * @see url::post()
	 */
	public function get_post(string $key, mixed $default = null, bool $unsafe = false): mixed {
		return $this->post($key, $default, $unsafe);
	}

	/**
	 * @deprecated Use input() instead.
	 * @see url::input()
	 */
	public function get_input(string $key, mixed $default = null, bool $unsafe = false): mixed {
		return $this->input($key, $default, $unsafe);
	}

	/**
	 * Returns the query parameter using the key
	 * @param string $key Key is converted to lowercase
	 * @param mixed $default
	 * @return mixed
	 */
	public function get_query_param(string $key, mixed $default = null, bool $unsafe = false): mixed {
		// framework specific to use lowercase only for param keys
		$key = strtolower($key);

		// filter is 0 for safe (sanitized) and 1 for unsafe (original)
		$filter = (int) $unsafe;

		// return the value if it exists, otherwise return the default
		return isset($this->params[$key][$filter]) ? $this->params[$key][$filter] : $default;
	}

	/**
	 * Returns true when the query parameter exists in the URL.
	 *
	 * @param string $key Query parameter name (case-insensitive).
	 * @return bool
	 */
	public function has_query_param(string $key): bool {
		$key = strtolower($key);

		return isset($this->params[$key]);
	}

	/**
	 * Returns true when the POST parameter was present in the loaded data
	 * (regardless of whether its filtered value passed validation).
	 *
	 * @param string $key POST parameter name (case-insensitive).
	 * @return bool
	 */
	public function has_post(string $key): bool {
		$key = strtolower($key);

		return isset($this->request_params[$key]) && $this->request_params[$key]['source'] === self::SOURCE_POST;
	}

	/**
	 * Reads and sanitizes the raw request body from php://input.
	 *
	 * - application/json bodies are decoded with json_decode().
	 * - All other bodies are parsed as form-encoded via parse_str().
	 *
	 * The Content-Type header is read automatically from $_SERVER.
	 * Safe to call multiple times; PHP permits repeated reads of php://input.
	 * php://input values yield to POST if the same key was already loaded.
	 *
	 * @return static
	 */
	public function load_input(): static {
		$raw = file_get_contents('php://input');
		if ($raw === false || $raw === '') {
			return $this;
		}
		$content_type = strtolower(
			$_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''
		);
		if (strpos($content_type, 'application/json') !== false) {
			$data = json_decode($raw, true);
			if (is_array($data)) {
				$this->import_request_params($data, self::SOURCE_INPUT, false);
			}
		} else {
			$params = [];
			parse_str($raw, $params);
			$this->import_request_params($params, self::SOURCE_INPUT, false);
		}

		return $this;
	}

	/**
	 * Loads $_REQUEST data as a fallback source.
	 *
	 * Only fills keys that are not already present from POST/php://input.
	 * GET values are still read from URL query params ($this->params) first.
	 *
	 * @param array $request Typically $_REQUEST.
	 * @return static
	 */
	public function load_request(array $request): static {
		$this->import_request_params($request, self::SOURCE_REQUEST, false);

		return $this;
	}

	/**
	 * Returns a sanitized value from the php://input parameter store.
	 *
	 * Only returns a value when the key was loaded via load_input() and was not
	 * already present as a POST value (POST wins over php://input).
	 * Use get() for a source-agnostic read.
	 *
	 * @param string $key     Parameter name (case-insensitive).
	 * @param mixed  $default Returned when the key is absent or came from a different source.
	 * @param bool   $unsafe  When true, returns the original unsanitized value.
	 * @return mixed
	 */
	public function input(string $key, mixed $default = null, bool $unsafe = false): mixed {
		$key  = strtolower($key);
		$slot = $unsafe ? self::UNSAFE : self::FILTERED;
		if (isset($this->request_params[$key]) && $this->request_params[$key]['source'] === self::SOURCE_INPUT) {
			return $this->request_params[$key][$slot] ?? $default;
		}

		return $default;
	}

	/**
	 * Returns true when the key exists in the php://input parameter store.
	 * Returns false when the same key was already loaded by POST (POST wins).
	 *
	 * @param string $key Parameter name (case-insensitive).
	 * @return bool
	 */
	public function has_input(string $key): bool {
		$key = strtolower($key);

		return isset($this->request_params[$key]) && $this->request_params[$key]['source'] === self::SOURCE_INPUT;
	}

	/**
	 * Alias of get(). Searches URL query params first, then POST, then php://input.
	 *
	 * @deprecated Use get() instead — behavior is identical.
	 * @see url::get()
	 */
	public function request(string $key, mixed $default = null, bool $unsafe = false): mixed {
		return $this->get($key, $default, $unsafe);
	}

	/**
	 * Calculate the SQL offset for the current page.
	 *
	 * @return int
	 */
	public function offset(): int {
		return $this->page * $this->rows_per_page;
	}

	/**
	 * Total number of pages given the current row count.
	 *
	 * @return int
	 */
	public function pages(): int {
		if ($this->rows_per_page > 0) {
			return (int) ceil($this->total_rows / $this->rows_per_page);
		}

		return 0;
	}

	/**
	 * Returns the number of rows per page.
	 *
	 * @return int
	 */
	public function get_rows_per_page(): int {
		return (int) $this->rows_per_page;
	}

	/**
	 * Set the current page number.
	 *
	 * @return static
	 */
	public function set_page(int $page): static {
		$this->page = max(0, $page);
		// Store as 1-based in the URL; drop the param entirely when on the first page
		if ($this->page > 0) {
			$this->set_query_param('page', $this->page + 1);
		} else {
			$this->unset_query_param('page');
		}

		return $this;
	}

	/**
	 * Get the current page number.
	 *
	 * @return int
	 */
	public function get_page(): int {
		return $this->page;
	}

	/**
	 * Set the settings object.
	 *
	 * @param settings $settings
	 */
	public function set_settings(settings $settings): void {
		$this->settings = $settings;
	}

	/**
	 * Get the settings object.
	 *
	 * @return settings
	 */
	public function get_settings(): settings {
		return $this->settings;
	}

	/**
	 * Return a clone pointing to the next page.
	 *
	 * @return static
	 */
	public function next(): static {
		$clone = clone $this;
		$clone->set_page($clone->page + 1);

		return $clone;
	}

	/**
	 * Return a clone pointing to the previous page.
	 *
	 * @return static
	 */
	public function prev(): static {
		$clone = clone $this;
		$clone->set_page(max(0, $clone->page - 1));

		return $clone;
	}

	/**
	 * Return a clone pointing to the first page.
	 *
	 * @return static
	 */
	public function page_first(): static {
		$clone = clone $this;
		$clone->unset_query_param('page');

		return $clone;
	}

	/**
	 * Get the total number of rows.
	 *
	 * @return int
	 */
	public function get_total_rows(): int {
		return $this->total_rows;
	}

	/**
	 * Set the total number of rows for the current query.
	 *
	 * @param int $total_rows Total number of rows in the result set.
	 * @return static
	 */
	public function set_total_rows(int $total_rows): static {
		$this->total_rows = max(0, $total_rows);

		return $this;
	}

	/**
	 * Build paging controls HTML.
	 *
	 * @param url  $url  URL object with paging state.
	 * @param bool $mini Render mini controls.
	 * @return string
	 */
	public static function html_paging_controls(url $url, bool $mini = false): string {
		global $text;

		if ($url->get_total_rows() <= 0) {
			return '';
		}

		$max_page = $url->pages();
		if ($url->pages() < 1) {
			return '';
		}

		$page_number = $url->get_page();

		$label_back = $text['button-back'] ?? 'Back';
		$label_next = $text['button-next'] ?? 'Next';
		$label_page = $text['label-page'] ?? 'Page';

		$prev_link = $url->prev()->build();
		$next_link = $url->next()->build();

		if (class_exists('button')) {
			if ($page_number > 0) {
				$prev = button::create([
					'type'  => 'button',
					'label' => (!$mini ? $label_back : null),
					'icon'  => 'chevron-left',
					'link'  => $prev_link,
					'title' => $label_page . ' ' . $page_number,
				]);
			} else {
				$prev = button::create([
					'type'    => 'button',
					'label'   => (!$mini ? $label_back : null),
					'icon'    => 'chevron-left',
					'style'   => 'opacity: 0.4; -moz-opacity: 0.4; cursor: default;',
					'onclick' => 'return false;',
				]);
			}

			$next_is_enabled = ($page_number < $max_page - 1);

			if ($next_is_enabled) {
				$next = button::create([
					'type'  => 'button',
					'label' => (!$mini ? $label_next : null),
					'icon'  => 'chevron-right',
					'link'  => $next_link,
					'title' => $label_page . ' ' . ($page_number + 2),
				]);
			} else {
				$next = button::create([
					'type'    => 'button',
					'label'   => (!$mini ? $label_next : null),
					'icon'    => 'chevron-right',
					'onclick' => 'return false;',
					'style'   => 'opacity: 0.4; -moz-opacity: 0.4; cursor: default;',
				]);
			}
		} else {
			$prev = "<a href='" . htmlspecialchars($prev_link, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($label_back, ENT_QUOTES, 'UTF-8') . "</a>";
			$next = "<a href='" . htmlspecialchars($next_link, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($label_next, ENT_QUOTES, 'UTF-8') . "</a>";
		}

		$html = '';
		if ($max_page > 1) {
			if ($mini) {
				$html = "<span style='white-space: nowrap;'>" . $prev . $next . "</span>\n";
			} else {
				$page_input_id = 'paging_page_num_' . substr(md5((string) $url->get_host() . ':' . $max_page), 0, 8);
				$html         .= "<script>\n";
				$html         .= "function fusionpbx_paging_go_" . $page_input_id . "(e) {\n";
				$html         .= "\tvar page_num = document.getElementById('" . $page_input_id . "').value;\n";
				$html         .= "\tvar do_action = false;\n";
				$html         .= "\tif (e != null) {\n";
				$html         .= "\t\tvar keyevent = window.event ? e.keyCode : e.which;\n";
				$html         .= "\t\tif (keyevent == 13) { do_action = true; }\n";
				$html         .= "\t\telse { return true; }\n";
				$html         .= "\t}\n";
				$html         .= "\telse { do_action = true; }\n";
				$html         .= "\tif (do_action) {\n";
				$html         .= "\t\tif (page_num < 1) { page_num = 1; }\n";
				$html         .= "\t\tif (page_num > " . $max_page . ") { page_num = " . $max_page . "; }\n";
				$go_url        = $url->delete('page')->build();
				$join          = (strpos((string) $go_url, '?') !== false) ? '&' : '?';
				$go_url_safe   = htmlspecialchars((string) $go_url, ENT_QUOTES, 'UTF-8');
				// page=1 means first page; omit the param for a clean URL
				$html         .= "\t\tif (page_num <= 1) { document.location.href = '" . $go_url_safe . "'; }\n";
				$html         .= "\t\telse { document.location.href = '" . $go_url_safe . $join . "page=' + page_num; }\n";
				$html         .= "\t\treturn false;\n";
				$html         .= "\t}\n";
				$html         .= "}\n";
				$html         .= "</script>\n";

				$html .= "<center style='white-space: nowrap;'>";
				$html .= $prev;
				$html .= "&nbsp;&nbsp;&nbsp;";
				$html .= "<input id='" . $page_input_id . "' class='formfld' style='max-width: 50px; min-width: 50px; text-align: center;' type='text' value='" . ($page_number + 1) . "' onfocus='this.select();' onkeypress='return fusionpbx_paging_go_" . $page_input_id . "(event);'>";
				$html .= "&nbsp;&nbsp;<strong>" . $max_page . "</strong>";
				$html .= "&nbsp;&nbsp;&nbsp;";
				$html .= $next;
				$html .= "</center>\n";
			}
		}

		return $html;
	}

	/**
	 * Build mini paging controls HTML.
	 *
	 * @param url $url URL object with paging state.
	 * @return string
	 */
	public static function html_paging_mini_controls(url $url): string {
		return self::html_paging_controls($url, true);
	}
}


/*

$url = new url('https://example.com/page?sort=asc');

// Add a custom filter — runs after the built-in default sanitizer
$url->add_query_filter(function (string $key, mixed $value, callable $next): mixed {
    // Drop any key named 'token' from safe params
    if ($key === 'token') {
        return null;
    }
    // Pass the (already-sanitized) value down the chain
    return $next($key, $value);
});

// Subclass extension point: override default_query_filter() for mandatory rules
class my_url extends url {
    protected function default_query_filter(string $key, mixed $value): mixed {
        $filtered = parent::default_query_filter($key, $value);
        if ($key === 'limit' && ((int)$filtered < 1 || (int)$filtered > 1000)) {
            return null;
        }
        return $filtered;
    }
}

*/

}