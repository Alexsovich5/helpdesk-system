<?php namespace Helpdesk\Http;

use Illuminate\Routing\UrlGenerator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use UnexpectedValueException;

/**
 * Keeps the request's Host header out of generated links.
 *
 * Laravel 4 builds URLs from the request's scheme and Host header, so a
 * request sent with "Host: evil.example" would put that host into the links
 * of the notification e-mails it triggers. Every URL is rooted at app.url
 * instead, and a request for a host that is neither app.url's host nor
 * listed in app.trusted_hosts is answered with 400.
 */
class HostGuard {

	/**
	 * app.url, which must be set explicitly in production.
	 *
	 * @param  string       $environment
	 * @param  string|bool  $configured  APP_URL (false when unset)
	 * @return string  without a trailing slash
	 * @throws RuntimeException
	 */
	public static function rootUrl($environment, $configured)
	{
		$url = is_string($configured) ? trim($configured) : '';

		if ($url === '')
		{
			if ($environment === 'production')
			{
				throw new RuntimeException('APP_URL must be set in production (the address users open, e.g. https://helpdesk.example.org).');
			}

			$url = 'http://localhost';
		}

		if ( ! preg_match('#^https?://[^/\s]+#i', $url))
		{
			throw new RuntimeException('APP_URL must be an absolute http or https URL.');
		}

		return rtrim($url, '/');
	}

	/**
	 * Host names requests may use: the host of app.url plus the extra names.
	 *
	 * @param  string        $url
	 * @param  string|array  $extra  comma-separated names or an array
	 * @return array
	 */
	public static function trustedHosts($url, $extra)
	{
		$hosts = array();
		$host = parse_url($url, PHP_URL_HOST);

		if ($host) $hosts[] = strtolower($host);

		if (is_string($extra)) $extra = explode(',', $extra);

		foreach ((array) $extra as $name)
		{
			$name = strtolower(trim($name));

			if ($name !== '' && ! in_array($name, $hosts, true)) $hosts[] = $name;
		}

		return $hosts;
	}

	/**
	 * Anchored, literal patterns for Request::setTrustedHosts().
	 *
	 * @param  array  $hosts
	 * @return array
	 */
	public static function patterns(array $hosts)
	{
		return array_map(function($host)
		{
			return '^'.preg_quote($host, '#').'$';
		}, $hosts);
	}

	/**
	 * Root every generated URL at $root, including its scheme.
	 */
	public static function apply(UrlGenerator $url, $root)
	{
		$url->forceRootUrl($root);

		$scheme = parse_url($root, PHP_URL_SCHEME);
		if ($scheme) $url->forceSchema(strtolower($scheme));
	}

	/**
	 * Apply app.url and the trusted hosts for one request.
	 *
	 * @return bool  false when the request's Host is not trusted
	 */
	public static function check(Request $request, UrlGenerator $url, $root, $extraHosts)
	{
		static::apply($url, $root);

		Request::setTrustedHosts(static::patterns(static::trustedHosts($root, $extraHosts)));

		try
		{
			$request->getHost();
		}
		catch (UnexpectedValueException $e)
		{
			return false;
		}

		return true;
	}

	/**
	 * Undo apply() and the trusted host list (tests).
	 */
	public static function reset(UrlGenerator $url)
	{
		$url->forceRootUrl(null);
		Request::setTrustedHosts(array());
	}

}
