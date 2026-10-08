<?php

use Illuminate\Auth\UserTrait;
use Illuminate\Auth\UserInterface;
use Illuminate\Auth\Reminders\RemindableTrait;
use Illuminate\Auth\Reminders\RemindableInterface;

class User extends Eloquent implements UserInterface, RemindableInterface {

	use UserTrait, RemindableTrait;

	/**
	 * Roles in ascending order of privilege; a role includes every role before it.
	 *
	 * @var array
	 */
	public static $roles = array('requester', 'agent', 'admin');

	/**
	 * The database table used by the model.
	 *
	 * @var string
	 */
	protected $table = 'users';

	/**
	 * The attributes excluded from the model's JSON form.
	 *
	 * @var array
	 */
	protected $hidden = array('password', 'remember_token');

	protected $fillable = array('username', 'name', 'email', 'role', 'source');

	/**
	 * True when the user's role is at least the given role.
	 *
	 * @param  string  $role
	 * @return bool
	 */
	public function hasRole($role)
	{
		$have = array_search($this->role, static::$roles, true);
		$need = array_search($role, static::$roles, true);

		if ($have === false || $need === false) return false;

		return $have >= $need;
	}

	/**
	 * Agents and admins work tickets.
	 *
	 * @return bool
	 */
	public function isAgent()
	{
		return $this->hasRole('agent');
	}

	public function isAdmin()
	{
		return $this->hasRole('admin');
	}

}
