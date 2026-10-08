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
	 * New users start active, as the column default does.
	 *
	 * @var array
	 */
	protected $attributes = array('active' => true);

	/**
	 * @return array
	 */
	public function getDates()
	{
		return array_merge(parent::getDates(), array('role_verified_at', 'last_login_at'));
	}

	/**
	 * Users who may receive staff e-mail: active agents and admins whose
	 * role is either local or was confirmed by the directory within the
	 * last $maxAgeDays days.
	 *
	 * @param  \Illuminate\Database\Eloquent\Builder  $query
	 * @param  \Carbon\Carbon  $now
	 * @param  int     $maxAgeDays
	 */
	public function scopeNotifiableStaff($query, $now, $maxAgeDays)
	{
		$cutoff = $now->copy()->subDays((int) $maxAgeDays);

		return $query->where('active', true)
			->whereIn('role', array('agent', 'admin'))
			->where(function($q) use ($cutoff)
			{
				$q->where('source', 'local')->orWhere('role_verified_at', '>=', $cutoff);
			});
	}

	/**
	 * @return bool
	 */
	public function isNotifiableStaff($now, $maxAgeDays)
	{
		if ( ! $this->active || ! $this->isAgent()) return false;

		if ($this->source === 'local') return true;

		return $this->role_verified_at && $this->role_verified_at->gte($now->copy()->subDays((int) $maxAgeDays));
	}

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
