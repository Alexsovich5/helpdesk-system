<?php namespace Helpdesk\Auth;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the "ldap" auth driver and binds LdapGateway to the
 * implementation named by the ldap.driver config key (native or fake).
 */
class LdapServiceProvider extends ServiceProvider {

	public function register()
	{
		$this->app->bindShared('Helpdesk\Auth\LdapGateway', function($app)
		{
			$config = $app['config']['ldap'];

			if ($config['driver'] === 'fake')
			{
				return new FakeLdapGateway($config['fake_users'], $config['user_filter']);
			}

			return new NativeLdapGateway($config, $app['log']->getMonolog());
		});

		$this->app->bind('Helpdesk\Auth\RoleMapper', function($app)
		{
			$map = $app['config']['ldap.role_map'];

			return is_array($map) ? new RoleMapper($map) : RoleMapper::fromString($map);
		});
	}

	public function boot()
	{
		$app = $this->app;

		$app['auth']->extend('ldap', function($app)
		{
			return new LdapUserProvider(
				$app->make('Helpdesk\Auth\LdapGateway'),
				$app->make('Helpdesk\Auth\RoleMapper'),
				$app['hash']
			);
		});
	}

}
