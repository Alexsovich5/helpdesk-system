<?php

class Category extends Eloquent {

	protected $table = 'categories';

	protected $fillable = array('name');

	public function tickets()
	{
		return $this->hasMany('Ticket');
	}

}
