<?php

class Asset extends Eloquent {

	public static $statuses = array('in_use', 'in_stock', 'repair', 'retired');

	/**
	 * Column sizes from the assets migration.
	 *
	 * @var array
	 */
	public static $maxLengths = array('asset_tag' => 50, 'name' => 255, 'type' => 50, 'serial' => 100, 'location' => 255);

	protected $table = 'assets';

	protected $fillable = array('asset_tag', 'name', 'type', 'serial', 'location', 'status');

	public function assignedUser()
	{
		return $this->belongsTo('User', 'assigned_user_id');
	}

	/**
	 * Tickets that reference the asset, newest first.
	 */
	public function tickets()
	{
		return $this->hasMany('Ticket')->orderBy('id', 'desc');
	}

	/**
	 * Assets a user may pick when raising a ticket: agents any asset that
	 * is not retired, everyone else only the assets assigned to them.
	 */
	public function scopeSelectableBy($query, User $user)
	{
		$query->where('status', '!=', 'retired');

		if ( ! $user->isAgent()) $query->where('assigned_user_id', $user->id);

		return $query;
	}

	/**
	 * @return string e.g. "LT-0101 - ThinkPad T440"
	 */
	public function label()
	{
		return $this->asset_tag.' - '.$this->name;
	}

}
