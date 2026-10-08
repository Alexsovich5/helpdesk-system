<?php

class KbCategory extends Eloquent {

	protected $table = 'kb_categories';

	protected $fillable = array('name');

	public function articles()
	{
		return $this->hasMany('KbArticle');
	}

}
