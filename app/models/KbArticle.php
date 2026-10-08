<?php

class KbArticle extends Eloquent {

	protected $table = 'kb_articles';

	protected $fillable = array('title', 'body');

	public function category()
	{
		return $this->belongsTo('KbCategory', 'kb_category_id');
	}

	public function author()
	{
		return $this->belongsTo('User', 'author_id');
	}

	public function tickets()
	{
		return $this->belongsToMany('Ticket', 'ticket_kb_article');
	}

	public function scopePublished($query)
	{
		return $query->where('is_published', true);
	}

	/**
	 * Agents can read drafts; everyone else only published articles.
	 */
	public function isVisibleTo(User $user)
	{
		return (bool) $this->is_published || $user->isAgent();
	}

}
