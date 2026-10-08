<?php namespace Helpdesk\Auth;

interface LdapGateway {

	/**
	 * Look a user up in the directory.
	 *
	 * @param  string  $username
	 * @return array|null ['dn'=>..,'username'=>..,'name'=>..,'email'=>..,
	 *                     'groups'=>[group DN,..],'disabled'=>bool];
	 *                     null when no entry, or more than one, matches
	 * @throws LdapUnavailableException  the directory could not be searched
	 */
	public function findUser($username);

	/**
	 * @param  string  $dn
	 * @param  string  $password
	 * @return bool true if a simple bind with $dn/$password succeeds
	 */
	public function bind($dn, $password);

}
